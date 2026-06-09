"""
Zendesk Auto Merge Service
Self-hosted replacement for myplaylist.io Auto Merge app.

Endpoint: GET /automerge/tickets/{ticket_id}?merge_rule_id=YOUR_RULE

Merge logic:
  - Open / New tickets from same requester → merged into the oldest one (hard merge)
  - Pending tickets from same requester → NOT merged; new ticket gets an internal
    comment warning the agent that a pending ticket already exists for this customer
"""

import os
import hmac
import hashlib
import base64
import logging
import requests
from flask import Flask, request, jsonify

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
log = logging.getLogger(__name__)

app = Flask(__name__)

# ---------------------------------------------------------------------------
# Config — set via environment variables or Cloud Run secrets
# ---------------------------------------------------------------------------
ZENDESK_SUBDOMAIN    = os.environ.get("ZENDESK_SUBDOMAIN", "")
ZENDESK_EMAIL        = os.environ.get("ZENDESK_EMAIL", "")
ZENDESK_API_TOKEN    = os.environ.get("ZENDESK_API_TOKEN", "")
WEBHOOK_SIGNING_KEY  = os.environ.get("WEBHOOK_SIGNING_KEY", "")  # from Zendesk webhook page

ZENDESK_BASE = f"https://{ZENDESK_SUBDOMAIN}.zendesk.com/api/v2"


def zd_auth():
    return (f"{ZENDESK_EMAIL}/token", ZENDESK_API_TOKEN)


def get_ticket(ticket_id: int) -> dict:
    r = requests.get(f"{ZENDESK_BASE}/tickets/{ticket_id}.json", auth=zd_auth(), timeout=10)
    r.raise_for_status()
    return r.json()["ticket"]


def search_tickets_by_requester(requester_id: int, exclude_id: int) -> dict:
    """
    Return all non-closed tickets from this requester (excluding the new ticket itself),
    bucketed into 'mergeable' (open/new) and 'pending'.
    No date filter — we look at everything still alive.
    """
    query = f"type:ticket requester_id:{requester_id} status<closed"
    r = requests.get(
        f"{ZENDESK_BASE}/search.json",
        params={"query": query, "sort_by": "created_at", "sort_order": "asc"},
        auth=zd_auth(),
        timeout=15,
    )
    r.raise_for_status()

    mergeable, pending = [], []
    for t in r.json().get("results", []):
        if t["id"] == exclude_id:
            continue
        if t["status"] in ("new", "open"):
            mergeable.append(t)
        elif t["status"] == "pending":
            pending.append(t)

    return {"mergeable": mergeable, "pending": pending}


def get_last_public_comment(ticket_id: int) -> str:
    """Get the last public comment from a ticket."""
    r = requests.get(
        f"{ZENDESK_BASE}/tickets/{ticket_id}/comments.json",
        auth=zd_auth(),
        timeout=10,
    )
    r.raise_for_status()
    comments = [c for c in r.json().get("comments", []) if c.get("public")]
    if not comments:
        return ""
    return comments[-1].get("body", "").strip()


def merge_tickets(target_id: int, source_ids: list, source_summary: str) -> dict:
    """Merge source tickets into target (oldest survives)."""
    payload = {
        "ids": source_ids,
        "target_comment": (
            f"The following duplicate ticket(s) have been automatically merged into this one:\n\n{source_summary}"
        ),
        "source_comment": (
            f"This ticket has been merged into ticket #{target_id} "
            f"(https://{ZENDESK_SUBDOMAIN}.zendesk.com/agent/tickets/{target_id})."
        ),
        "target_comment_is_public": False,
        "source_comment_is_public": False,
    }
    r = requests.post(
        f"{ZENDESK_BASE}/tickets/{target_id}/merge.json",
        json=payload,
        auth=zd_auth(),
        timeout=15,
    )
    r.raise_for_status()
    return r.json()


def add_internal_comment(ticket_id: int, body: str) -> None:
    """Add an internal (private) note to a ticket."""
    payload = {
        "ticket": {
            "comment": {
                "body": body,
                "public": False,
            }
        }
    }
    r = requests.put(
        f"{ZENDESK_BASE}/tickets/{ticket_id}.json",
        json=payload,
        auth=zd_auth(),
        timeout=10,
    )
    r.raise_for_status()


# ---------------------------------------------------------------------------
# Route
# ---------------------------------------------------------------------------

@app.route("/automerge/tickets/<int:ticket_id>", methods=["GET"])
def automerge(ticket_id: int):
    # Verify Zendesk webhook signature (HMAC-SHA256)
    if WEBHOOK_SIGNING_KEY:
        signature = request.headers.get("X-Zendesk-Webhook-Signature", "")
        timestamp  = request.headers.get("X-Zendesk-Webhook-Signature-Timestamp", "")
        body       = request.get_data(as_text=True)
        expected   = base64.b64encode(
            hmac.new(
                WEBHOOK_SIGNING_KEY.encode(),
                (timestamp + body).encode(),
                hashlib.sha256,
            ).digest()
        ).decode()
        if not hmac.compare_digest(signature, expected):
            log.warning("Invalid webhook signature for ticket %d", ticket_id)
            return jsonify({"error": "Unauthorized"}), 401

    merge_rule_id = request.args.get("merge_rule_id", "default")
    log.info("Automerge triggered: ticket=%d rule=%s", ticket_id, merge_rule_id)

    try:
        ticket       = get_ticket(ticket_id)
        requester_id = ticket["requester_id"]
        log.info("Ticket %d requester_id=%d status=%s", ticket_id, requester_id, ticket["status"])

        buckets   = search_tickets_by_requester(requester_id, exclude_id=ticket_id)
        mergeable = buckets["mergeable"]
        pending   = buckets["pending"]

        result = {
            "ticket_id":          ticket_id,
            "merged_ids":         [],
            "pending_warned_ids": [],
        }

        # 1. Hard-merge open/new duplicates
        if mergeable:
            all_ids = sorted([ticket_id] + [t["id"] for t in mergeable])
            target  = all_ids[0]
            sources = all_ids[1:]
            log.info("Merging %s → ticket %d", sources, target)

            summary_parts = []
            for sid in sources:
                link         = f"https://{ZENDESK_SUBDOMAIN}.zendesk.com/agent/tickets/{sid}"
                last_comment = get_last_public_comment(sid)
                part         = f"• #{sid} ({link})"
                if last_comment:
                    part += f"\n\n  Last message:\n  {last_comment}"
                summary_parts.append(part)
            source_summary = "\n\n".join(summary_parts)

            merge_tickets(target, sources, source_summary)
            result["merged_ids"]        = sources
            result["target_ticket_id"]  = target

        # 2. Warn about pending tickets (comment only, no merge)
        if pending:
            pending_ids   = [t["id"] for t in pending]
            pending_links = ", ".join(
                f"#{tid} (https://{ZENDESK_SUBDOMAIN}.zendesk.com/agent/tickets/{tid})"
                for tid in pending_ids
            )
            comment_body = (
                f"⚠️ Heads up: this customer already has a ticket in Pending status "
                f"({pending_links}). Please review before responding to avoid duplicate work."
            )
            add_internal_comment(ticket_id, comment_body)
            log.info("Pending warning added to ticket %d re: %s", ticket_id, pending_ids)
            result["pending_warned_ids"] = pending_ids

        result["status"] = "no_duplicates" if not mergeable and not pending else "processed"
        return jsonify(result), 200

    except requests.HTTPError as e:
        log.error("Zendesk API error: %s %s", e.response.status_code, e.response.text)
        return jsonify({"error": "zendesk_api_error", "detail": e.response.text}), 502
    except Exception as e:
        log.exception("Unexpected error for ticket %d", ticket_id)
        return jsonify({"error": str(e)}), 500


@app.route("/health", methods=["GET"])
def health():
    return jsonify({"status": "ok"}), 200


if __name__ == "__main__":
    port = int(os.environ.get("PORT", 8080))
    app.run(host="0.0.0.0", port=port)

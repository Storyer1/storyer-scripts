#!/bin/bash
# Deploy zendesk-automerge to Google Cloud Run
# Usage: ./deploy.sh

set -euo pipefail

# ── Edit these ────────────────────────────────────────────────────────────────
PROJECT="your-gcp-project-id"
REGION="europe-west1"          # or us-central1, asia-east1 etc.
# ─────────────────────────────────────────────────────────────────────────────

SERVICE="zendesk-automerge"
IMAGE="gcr.io/${PROJECT}/${SERVICE}"

echo "▶ Building image..."
gcloud builds submit --tag "${IMAGE}" --project "${PROJECT}"

echo "▶ Deploying to Cloud Run..."
gcloud run deploy "${SERVICE}" \
  --image "${IMAGE}" \
  --platform managed \
  --region "${REGION}" \
  --project "${PROJECT}" \
  --allow-unauthenticated \
  --memory 256Mi \
  --cpu 1 \
  --min-instances 0 \
  --max-instances 5 \
  --timeout 30 \
  --set-env-vars "ZENDESK_SUBDOMAIN=${ZENDESK_SUBDOMAIN}" \
  --set-env-vars "ZENDESK_EMAIL=${ZENDESK_EMAIL}" \
  --set-env-vars "ZENDESK_API_TOKEN=${ZENDESK_API_TOKEN}" \
  --set-env-vars "WEBHOOK_SIGNING_KEY=${WEBHOOK_SIGNING_KEY}"

SERVICE_URL=$(gcloud run services describe "${SERVICE}" \
  --platform managed --region "${REGION}" --project "${PROJECT}" \
  --format "value(status.url)")

echo ""
echo "✅ Deployed: ${SERVICE_URL}"
echo ""
echo "Zendesk webhook URL:"
echo "  ${SERVICE_URL}/automerge/tickets/{{ticket.id}}"

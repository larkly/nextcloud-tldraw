# Kubernetes Deployment Guide

This directory contains Kubernetes manifests for deploying the tldraw collab server.

## Files

| File | Purpose |
|---|---|
| `secret.yaml.example` | Secret template — copy, fill in, and apply (never commit the filled version) |
| `deployment.yaml` | Main workload |
| `service.yaml` | ClusterIP service exposing port 3000 |
| `ingress-nginx.yaml` | Ingress for nginx-ingress-controller with WebSocket support |
| `ingress-traefik.yaml` | IngressRoute for Traefik v2/v3 (CRD-based) with WebSocket support |

---

## Prerequisites

- Kubernetes 1.24+
- One of:
  - **nginx-ingress-controller** → use `ingress-nginx.yaml`
  - **Traefik v2/v3** as a Kubernetes controller with CRDs installed → use `ingress-traefik.yaml`
- **cert-manager** (recommended for TLS) or manually-managed TLS Secrets
- Image pull access to `ghcr.io/larkly/nextcloud-tldraw:latest` (public, no credentials required)

---

## Single-Replica Constraint

> **Do not increase `replicas` beyond 1 without adding shared state.**

Room state is stored in an in-memory `Map` inside the collab server process
(`collab-server/src/room-manager.ts`). With more than one replica and no sticky
sessions, a user on pod A and a user on pod B editing the same file would each
see only their own changes — edits diverge silently.

**Upgrade path to multiple replicas:**

1. Replace the in-memory room map with a shared store (e.g. Redis Pub/Sub or a
   shared SQLite file on a `ReadWriteMany` PVC).
2. Or enable session affinity on the ingress so all WebSocket connections for a
   given room always land on the same pod:
   - nginx: `nginx.ingress.kubernetes.io/affinity: "cookie"`
   - Traefik: use a `StickySession` service configuration

The `deployment.yaml` includes a `podAntiAffinity` rule that prevents the
scheduler from placing two `tldraw-sync` pods on the same node, making an
accidental scale-up visible as a scheduling failure rather than a silent data
split.

---

## Quick Start

### 1. Create the namespace

```bash
kubectl create namespace tldraw
```

### 2. Create the Secret

```bash
cp k8s/secret.yaml.example k8s/secret.yaml
# Edit secret.yaml — replace the placeholder base64 values with real ones:
#   echo -n 'your-value' | base64
vim k8s/secret.yaml
kubectl apply -f k8s/secret.yaml
```

### 3. Apply the remaining manifests

Choose the ingress file that matches your controller:

```bash
# nginx-ingress
kubectl apply -f k8s/deployment.yaml -f k8s/service.yaml -f k8s/ingress-nginx.yaml

# Traefik (CRD-based)
kubectl apply -f k8s/deployment.yaml -f k8s/service.yaml -f k8s/ingress-traefik.yaml
```

Or apply the whole directory (edit or delete the ingress file you don't need first):

```bash
kubectl apply -f k8s/
```

### 4. Verify

```bash
kubectl -n tldraw get pods
kubectl -n tldraw logs deployment/tldraw-sync
# Should print: Collab Server running on port 3000

# Health check through the service
kubectl -n tldraw port-forward svc/tldraw-sync 3000:3000 &
curl http://localhost:3000/health
# {"status":"ok"}
```

---

## Dry-Run Validation

```bash
kubectl apply --dry-run=client -f k8s/deployment.yaml -f k8s/service.yaml -f k8s/ingress-nginx.yaml
```

---

## Migrating from Docker Compose

The environment variables are identical. Map your `.env` values directly to the
Secret:

| `.env` key | Secret key |
|---|---|
| `JWT_SECRET_KEY` | `JWT_SECRET_KEY` |
| `NC_URL` | `NC_URL` |
| `NC_USER` | `NC_USER` |
| `NC_PASS` | `NC_PASS` |

`TLDRAW_HOST` and `ACME_EMAIL` are not needed — hostname and TLS are handled by
the Ingress / cert-manager instead.

After switching, update the **Collab Server URL** in Nextcloud's Administration
Settings to point to your new ingress hostname (e.g. `https://tldraw.example.com`).

---

## TLS Options

### cert-manager (recommended)

Install cert-manager and create a `ClusterIssuer`, then the manifests will
automatically provision certificates. The `ingress-nginx.yaml` annotation
`cert-manager.io/cluster-issuer: letsencrypt-prod` triggers this.

For Traefik, create a `Certificate` resource in the `tldraw` namespace:

```yaml
apiVersion: cert-manager.io/v1
kind: Certificate
metadata:
  name: tldraw-tls
  namespace: tldraw
spec:
  secretName: tldraw-tls
  dnsNames:
    - tldraw.example.com
  issuerRef:
    name: letsencrypt-prod
    kind: ClusterIssuer
```

### Manual TLS

Create a TLS Secret directly and reference it in the ingress:

```bash
kubectl -n tldraw create secret tls tldraw-tls \
  --cert=path/to/tls.crt \
  --key=path/to/tls.key
```

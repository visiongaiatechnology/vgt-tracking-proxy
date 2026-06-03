# 📡 VGT Self-Hosted Tracking Proxy — Community Edition

[![License](https://img.shields.io/badge/License-AGPLv3-green?style=for-the-badge)](LICENSE)
[![Version](https://img.shields.io/badge/Version-2.3.3-brightgreen?style=for-the-badge)](#)
[![Platform](https://img.shields.io/badge/Platform-WordPress%20%2F%20WooCommerce-21759B?style=for-the-badge&logo=wordpress)](#)
[![Architecture](https://img.shields.io/badge/Architecture-Server--Side_Gateway-red?style=for-the-badge)](#)
[![Privacy](https://img.shields.io/badge/Privacy-DSGVO%20%2F%20GDPR_Sovereign-blue?style=for-the-badge)](#)
[![Status](https://img.shields.io/badge/Status-STABLE-brightgreen?style=for-the-badge)](#)
[![VGT](https://img.shields.io/badge/VGT-VisionGaia_Technology-red?style=for-the-badge)](https://visiongaiatechnology.de)

> *"No client-side scripts. No ad-blocker vulnerabilities. No DSGVO grey zones."*
> *AGPLv3 — Open Source Core. Built for EU businesses, not for SaaS margins.*

---

## ⚠️ DISCLAIMER: EXPERIMENTAL R&D PROJECT

This project is a **Proof of Concept (PoC)** and part of ongoing research and development at VisionGaia Technology. It is **not** a certified or production-ready product.

**Use at your own risk.** The software may contain bugs or unexpected behavior under non-standard server configurations. Validate the plugin in a staging environment before deploying to live shops.

**API Compatibility Notice:** The Meta Conversions API and Google Analytics 4 Measurement Protocol are third-party interfaces subject to change without notice. VGT assumes no liability for tracking interruptions caused by upstream API changes. The Community Edition does not include automated interface update guarantees — this is a **Premium Module** feature.

Found a bug or have an improvement? **Open an issue or contact us.**

---

<img width="1237" height="876" alt="image" src="https://github.com/user-attachments/assets/ac56f730-2eab-4fb3-9ee0-5f84c8f6b8b8" />


## 🔍 What is the VGT Tracking Proxy?

The **VGT Self-Hosted Tracking Proxy** is a zero-dependency, server-side tracking gateway for WordPress and WooCommerce. It intercepts e-commerce events at the PHP level, anonymizes all personally identifiable data (PII) in volatile server memory, and forwards clean event payloads directly to advertising networks via server-to-server APIs — bypassing ad-blockers, browser restrictions, and third-party CDN dependencies entirely.

```
Traditional Client-Side Tracking:
→ Meta Pixel loaded in browser        — blocked by ~40% of users
→ GA4 script executes client-side     — ITP/ETP strips attribution
→ Raw PII sent to third-party CDNs    — DSGVO grey zone
→ Webhook payments lose attribution   — blind spot in conversion data

VGT Server-Side Gateway:
→ Zero browser scripts for tracking   — ad-blockers irrelevant
→ PII anonymized in PHP memory        — never touches the database
→ Server-to-server API transmission   — direct, verified, compliant
→ Webhook Blind Spot solved           — 100% attribution rate
→ AES-256-GCM encrypted credentials  — keys never stored in plaintext
```

---

## 🎯 Who is this for?

| Target | Pain Point Solved |
|---|---|
| **WooCommerce Shop Operators** | 30–40% conversion data loss from ad-blockers and browser privacy policies |
| **Performance Marketing Agencies** | Inaccurate ROAS data due to client-side tracking gaps — wasted ad spend |
| **EU Businesses** | DSGVO-compliant server-side tracking without relying on external SaaS infrastructure |
| **Developers** | Self-hosted, auditable, zero-dependency implementation — no black-box SaaS subscriptions |

---

## 🏗️ Architecture

```
WooCommerce Event Triggered
(add_to_cart / begin_checkout / purchase)
              ↓
WooCommerceBridge (Event Interceptor)
→ Hooks into WC lifecycle at PHP level
→ Extracts attribution cookies (_fbp, _fbc, _ga, _ga_X)
→ Persists attribution to Order Meta (Webhook Blind Spot fix)
              ↓
Anonymizer (Privacy Engine — RAM only)
→ SHA-256 hash: email + phone (irreversible)
→ IPv4/IPv6 masking before any persistence
→ PII never written to disk or database
              ↓
QueueDispatcher (Async Pipeline)
→ Enqueues payload to WP Action Scheduler
→ Frontend latency impact: 0ms
→ WP-Cron fallback if Action Scheduler unavailable
              ↓
        ┌─────────────────────┐
        ↓                     ↓
MetaCapiClient          Ga4MpClient
Graph API v20.0         Measurement Protocol
Server-to-Server        Server-to-Server
              ↓
AuditLogger (Transparency Layer)
→ Every transmission logged with status + payload excerpt
→ AES-256-GCM encrypted credentials in storage
→ Auto-rotation: 30-day log purge via daily Cron
```

---

## 🧩 Module Reference

### 📡 WooCommerceBridge — Event Interceptor

Hooks into the WooCommerce lifecycle at PHP level. Intercepts four key e-commerce events and constructs clean, anonymized payloads for dispatch.

| Event | WC Hook | Trigger |
|---|---|---|
| `ViewContent` | `woocommerce_after_single_product` | Product page loaded |
| `AddToCart` | `woocommerce_add_to_cart` | Item added to cart |
| `InitiateCheckout` | `woocommerce_checkout_create_order` | Checkout started + attribution persisted |
| `Purchase` | `woocommerce_payment_complete` | Payment confirmed (+ webhook fallback) |

---

### 🔒 Anonymizer — Privacy Engine

All PII is processed exclusively in volatile PHP memory. No personally identifiable data is written to any database or log file.

| Operation | Mechanism |
|---|---|
| **Email Hashing** | Normalized (lowercase, trimmed) → SHA-256 irreversible hash |
| **Phone Hashing** | Digits + leading `+` extracted → SHA-256 irreversible hash |
| **IPv4 Masking** | Last octet zeroed: `192.168.1.55` → `192.168.1.0` |
| **IPv6 Masking** | Last 4 segments zeroed (interface identifier nulled) |
| **Client ID** | UUID v4 via `random_int()` — cookie-persisted, no PII |

---

### ⚡ QueueDispatcher — Async Pipeline

Decouples event dispatch from the customer's request cycle. WooCommerce purchases complete at full speed — API calls to Meta and Google happen asynchronously in the background.

- **Primary:** WP Action Scheduler (recommended — precise, reliable)
- **Fallback:** WordPress Cron (activated automatically if Action Scheduler unavailable)
- **Frontend latency impact:** 0ms

---

### 🔑 Cryptor — Credential Security

API keys (Meta System User Token, GA4 API Secret) are never stored in plaintext.

| Parameter | Value |
|---|---|
| **Algorithm** | AES-256-GCM |
| **Authentication** | GCM auth tag — integrity verified on every read |
| **Key Derivation** | WordPress `SECURE_AUTH_KEY` salt |
| **Scope** | wp_options table encryption at rest |

---

### 📋 AuditLogger — Transmission Transparency

Every outbound API call is recorded with full transmission metadata.

| Feature | Detail |
|---|---|
| **Logged Fields** | Timestamp, Event Name, API Target, HTTP Status, Payload Excerpt |
| **XSS Protection** | All dashboard output via `esc_html()` / `esc_attr()` |
| **Log Rotation** | Automatic 30-day purge via `vgt_proxy_daily_cleanup` cron |
| **Manual Purge** | Admin Console → one-click with nonce-verified confirmation |

---

## 🔍 The Webhook Blind Spot — Solved

This is the tracking gap that affects every WooCommerce store using external payment gateways (PayPal, Stripe, Klarna, Mollie).

**The Problem:**
When a payment provider completes a transaction via server-to-server webhook, the PHP execution context has no access to the customer's browser cookies (`_fbp`, `_fbc`, `_ga`). The purchase fires — but attribution data is missing. The conversion is invisible to Meta and Google.

**The VGT Solution — State-Persistence Bridge:**

```
Step 1: CAPTURE
  woocommerce_checkout_create_order fires in the browser context
  → WooCommerceBridge reads _fbp, _fbc, _ga_client_id, _ga_session_id from cookies
  → Values persisted as protected Order Meta (_vgt_fbp, _vgt_ga_client_id, etc.)

Step 2: EXECUTE
  woocommerce_payment_complete fires later (webhook context, no browser)
  → WooCommerceBridge reads attribution IDs from Order Meta
  → Full conversion signal dispatched to Meta CAPI + GA4 MP
  → Attribution rate: up to 100%
```

No external service. No cookie-sync workaround. State preserved natively in WooCommerce order data.

---

## 🛡️ DSGVO / GDPR Sovereignty

The gateway is designed so that no personally identifiable data leaves your server infrastructure in any readable form.

- **PII hashed before queue:** Email and phone are SHA-256 hashed in PHP memory before the payload is passed to the Action Scheduler database. No plaintext PII is ever written to `wp_actionscheduler_actions`.
- **IP anonymized before queue:** Same principle — masked in RAM, only the anonymized address persists anywhere.
- **Credentials encrypted at rest:** API tokens stored with AES-256-GCM. Plaintext only exists in PHP memory during active API calls.
- **Zero third-party CDNs:** No external scripts loaded in the customer's browser for tracking purposes. The tracking gateway operates entirely server-side.
- **Self-hosted:** Your data stays on your infrastructure. No SaaS intermediary. No data processing agreements with cloud providers required for the tracking pipeline itself.

---

## 🔓 Open Core vs. Premium

| Capability | Community (AGPLv3) | Premium |
|---|---|---|
| Meta Conversions API (CAPI) | ✅ | ✅ |
| GA4 Measurement Protocol | ✅ | ✅ |
| DSGVO Anonymizer (SHA-256 + IP masking) | ✅ | ✅ |
| Webhook Blind Spot fix (State-Persistence Bridge) | ✅ | ✅ |
| AES-256-GCM Credential Encryption | ✅ | ✅ |
| Audit Logger (30-day rotation) | ✅ | ✅ |
| WooCommerce Bridge (4 events) | ✅ | ✅ |
| **TikTok Events API** | ❌ | ✅ |
| **Snapchat Conversions API** | ❌ | ✅ |
| **Pinterest API for Conversions** | ❌ | ✅ |
| **White-Label Admin Console** | ❌ | ✅ |
| **Multi-Site Manager** | ❌ | ✅ |
| **Automated API Interface Updates** | ❌ | ✅ |
| **Priority Support** | ❌ | ✅ |

> **Premium modules are currently in development.** Follow [@VisionGaia Technology](https://visiongaiatechnology.de) for release announcements.

---

## ⚙️ Technical Specifications

| Parameter | Minimum | Recommended |
|---|---|---|
| **PHP** | 8.1+ | 8.2 / 8.3 (JIT performance) |
| **WordPress** | 6.0+ | Latest stable |
| **WooCommerce** | 7.0+ | Latest stable |
| **PHP Extensions** | `openssl`, `curl`, `mbstring` | Standard server config |
| **Database** | MySQL 5.7+ / MariaDB 10.3+ | InnoDB engine |
| **Background Jobs** | WP Cron active | System-level cron + Action Scheduler |

---

## 🚀 Installation

```bash
# 1. Clone into WordPress plugins directory
cd /var/www/html/wp-content/plugins/
git clone https://github.com/visiongaiatechnology/vgt-tracking-proxy

# 2. Activate in WordPress Admin
# Plugins → VGT Tracking Proxy → Activate
```

On activation, the plugin automatically:

```
→ Creates the audit log database table
→ Registers async Action Scheduler hooks
→ Initializes WooCommerce event interceptors
→ Activates the daily log rotation cron
```

**Configuration:**
1. Navigate to **WooCommerce → VGT Tracking Proxy**
2. Enable Meta CAPI and/or GA4 Measurement Protocol
3. Enter your credentials (stored AES-256-GCM encrypted)
4. Enable DSGVO IP masking (recommended for EU deployments)
5. Monitor transmission status in the Audit Stream

**Action Scheduler (recommended):**
Install [WooCommerce Action Scheduler](https://actionscheduler.org/) or ensure WooCommerce is active — Action Scheduler ships with WooCommerce. Without it, the gateway falls back to WP Cron automatically.

---

## 🔗 VGT Ecosystem

| Tool | Type | Purpose |
|---|---|---|
| 📡 **VGT Tracking Proxy** | **Server-Side Tracking** | DSGVO-sovereign Meta CAPI + GA4 gateway — you are here |
| ⚔️ **[VGT Sentinel](https://github.com/visiongaiatechnology/sentinelcom)** | **WAF / IDS Framework** | Zero-Trust WordPress security suite |
| 📊 **[VGT Dattrack](https://github.com/visiongaiatechnology/dattrack)** | **Analytics** | Sovereign analytics engine — your data, your server |
| 🛡️ **[VGT Myrmidon](https://github.com/visiongaiatechnology/vgtmyrmidon)** | **ZTNA** | Zero Trust device registry and cryptographic integrity verification |
| ⚡ **[VGT Auto-Punisher](https://github.com/visiongaiatechnology/vgt-auto-punisher)** | **IDS** | L4+L7 Hybrid IDS — attackers terminated at network layer |
| 🌐 **[VGT Global Threat Sync](https://github.com/visiongaiatechnology/vgt-global-threat-sync)** | **Threat Intel** | Daily threat feed — block known attackers before they arrive |
| 🔥 **[VGT Windows Firewall Burner](https://github.com/visiongaiatechnology/vgt-windows-burner)** | **Windows** | 280,000+ APT IPs blocked in native Windows Firewall |

---

## 💰 Support the Project

[![Donate via PayPal](https://img.shields.io/badge/Donate-PayPal-00457C?style=for-the-badge&logo=paypal)](https://www.paypal.com/paypalme/dergoldenelotus)

| Method | Address |
|---|---|
| **PayPal** | [paypal.me/dergoldenelotus](https://www.paypal.com/paypalme/dergoldenelotus) |
| **Bitcoin** | `bc1q3ue5gq822tddmkdrek79adlkm36fatat3lz0dm` |
| **ETH / USDT (ERC-20)** | `0xD37DEfb09e07bD775EaaE9ccDaFE3a5b2348Fe85` |

---

## 🤝 Contributing

Pull requests are welcome. For major changes, open an issue first to align on direction.

Licensed under **AGPLv3** — any SaaS deployment of this codebase must publish its modifications under the same license.

---

## 🏢 Built by VisionGaia Technology

[![VGT](https://img.shields.io/badge/VGT-VisionGaia_Technology-red?style=for-the-badge)](https://visiongaiatechnology.de)

VisionGaia Technology builds sovereign infrastructure for the open web — engineered to the DIAMANT VGT SUPREME standard.

> *"This gateway was built because EU businesses deserve server-side tracking that doesn't route their customer data through a San Francisco SaaS dashboard, doesn't charge €40/month for what is fundamentally a PHP HTTP client, and doesn't leave a DSGVO liability hanging over every conversion event."*

---

*Version 2.3.3 — VGT Self-Hosted Tracking Proxy // Server-Side Gateway // Meta CAPI + GA4 Measurement Protocol // DSGVO-Sovereign // AES-256-GCM // Webhook Blind Spot Solved // AGPLv3*****

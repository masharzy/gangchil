# GANGCHIL — Restaurant & Kabsa Place

Mobile-first restaurant ordering and operations application.

## Stack
- Node.js 20+, Express, Socket.IO
- PostgreSQL (Neon or other managed Postgres)
- Vanilla HTML/CSS/JavaScript frontend served by Express
- Cash-only ordering, role-based staff access

## Setup
1. Run `npm install`.
2. Copy `.env.example` to `.env` and set `DATABASE_URL`, a random 32+ character `SESSION_SECRET`, `ADMIN_EMAIL`, and `ADMIN_PASSWORD` (12+ characters).
3. Run `npm run migrate`.
4. Run `npm run seed` once to create the initial Owner, categories, and tables.
5. Run `npm start` and open http://localhost:3000.

## Render
Use the repository's `render.yaml` Blueprint or create a Node Web Service manually. Set the environment variables from `.env.example`. Configure `PUBLIC_ORIGIN` to the final HTTPS origin.

**Important:** The first owner must be created using `npm run seed` with `ADMIN_EMAIL` and `ADMIN_PASSWORD` configured. This is a separate one-time command; `render.yaml` runs migrations but does not seed automatically.

## GitHub Actions health check
Set repository Actions secret `RENDER_HEALTH_URL` to the Render service's `/health` URL. Scheduled runs are best-effort and can be delayed. Do not rely on this for production uptime.

## Security / deployment notes
- Do not commit `.env` or credentials.
- PostgreSQL database must be persistent and backed up independently of Render.
- WebSocket requires authenticated staff sessions; customers order using REST.
- Test order lifecycle, roles, reports and migrations before handling real restaurant orders.
- The logo asset `public/logo.jpg` must be added separately if absent from the repository.

import type { APIRequestContext } from '@playwright/test';

/** Logs a small, varied set of HTTP exchanges (GET, JSON POST, 404) through the demo endpoints (async: false). */
export async function seedTraffic(request: APIRequestContext): Promise<void> {
  await request.get('/');
  await request.get('/api/ping');
  await request.post('/api/echo', { data: { order: 1042, customer: 'Lucía Fernández', items: [{ sku: 'TOTE-01', qty: 2 }] } });
  await request.get('/api/ping?source=healthcheck');
  await request.get('/api/orders/1042', { failOnStatusCode: false });
  await request.post('/api/echo', { data: { event: 'order.paid', id: 1042 } });
}

// Load-test voor 't Klosterke. Eén iteratie = één request, gekozen volgens de verkeersmix.
//
// Mix (aanname, zie docs/implementatie.md): 90% GET /game, 8% GET /order/status, 2% POST /order/new.
// GET /order/status is niet gebouwd en geeft 501 (zie public/index.php). Dat tellen we hier als
// verwacht, zodat het aantal requests en de mix kloppen.
//
// Gebruik: ./bin/loadtest.sh  (eigen stack met eigen database; raakt de echte voorraad niet)
// Instellen: TARGET_RPS (standaard 3500), HOLD_SECONDS (standaard 120), BASE_URL.
//
// Elke bestelling heeft een nieuwe bezoeker: de limiet van 2 openstaande orders per bezoeker en de
// voorraad zijn bedoeld voor mensen, niet voor de meting. bin/loadtest.sh zet daarom een enorme
// voorraad en geen limiet op openstaande orders.

import http from 'k6/http';

const BASE_URL = __ENV.BASE_URL || 'http://nginx';
const TARGET_RPS = Number(__ENV.TARGET_RPS || 3500);
const HOLD = `${Number(__ENV.HOLD_SECONDS || 120)}s`; // vasthouden; loadtest/sweep.sh gebruikt korter

export const options = {
  scenarios: {
    mix: {
      executor: 'ramping-arrival-rate', // vaste aanbiedsnelheid, ook als de server traag wordt
      startRate: 100,
      timeUnit: '1s',
      preAllocatedVUs: 500,
      maxVUs: 3000,
      stages: [
        { target: Math.round(TARGET_RPS * 0.3), duration: '30s' },
        { target: Math.round(TARGET_RPS * 0.6), duration: '30s' },
        { target: TARGET_RPS, duration: '30s' },
        { target: TARGET_RPS, duration: HOLD }, // vasthouden op de eis
        { target: 0, duration: '10s' },
      ],
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.01'],
    // p(95)<100 = 95% van de verzoeken sneller dan 100 ms
    'http_req_duration{name:game}': ['p(95)<100'],
    'http_req_duration{name:order_new}': ['p(95)<500'],
    // Generator kon de gevraagde snelheid niet halen = meting niet geldig
    dropped_iterations: ['count<100'],
  },
};

function getGame() {
  http.get(`${BASE_URL}/game`, {
    tags: { name: 'game' },
    responseCallback: http.expectedStatuses(200),
  });
}

function getOrderStatus() {
  http.get(`${BASE_URL}/order/status?order_id=1`, {
    tags: { name: 'order_status' },
    responseCallback: http.expectedStatuses(501),
  });
}

function postOrder() {
  const body = JSON.stringify({
    idempotency_key: `k6-${__VU}-${__ITER}-${Date.now()}`,
    visitor_id: `k6-visitor-${__VU}-${__ITER}`,
    items: [{ product_id: 1 + Math.floor(Math.random() * 3), quantity: 1 + Math.floor(Math.random() * 3) }],
  });
  http.post(`${BASE_URL}/order/new`, body, {
    headers: { 'Content-Type': 'application/json' },
    tags: { name: 'order_new' },
    responseCallback: http.expectedStatuses(201),
  });
}

export default function () {
  const r = Math.random();
  if (r < 0.9) getGame();
  else if (r < 0.98) getOrderStatus();
  else postOrder();
}

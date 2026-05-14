// k6 load test — single target
// Usage:
//   k6 run -e BASE_URL=http://localhost:8000 benchmarks/load_test.js   ← Swoole
//   k6 run -e BASE_URL=http://localhost:8080 benchmarks/load_test.js   ← FPM

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Trend, Rate } from 'k6/metrics';

const latency = new Trend('request_latency', true);
const errors  = new Rate('error_rate');

export const options = {
  stages: [
    { duration: '10s', target: 20  },  // ramp up
    { duration: '30s', target: 100 },  // sustained load
    { duration: '10s', target: 0   },  // ramp down
  ],
  thresholds: {
    http_req_duration: ['p(99)<2000'],
    error_rate:        ['rate<0.01'],
  },
};

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8000';

export default function () {
  const res = http.get(`${BASE_URL}/ping`);

  const ok = check(res, {
    'status 200': r => r.status === 200,
    'has db key': r => {
      try { return JSON.parse(r.body).db === true; } catch { return false; }
    },
  });

  latency.add(res.timings.duration);
  errors.add(!ok);

  sleep(0.1);
}
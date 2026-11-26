// OwnMemories — API istemcisi (oturum çerezi + CSRF).
import { OM } from './util.js';

export class ApiError extends Error {
  constructor(status, code, message, extra = {}) { super(message); this.status = status; this.code = code; this.extra = extra; }
}

export async function api(method, path, body, opts = {}) {
  const headers = { 'X-CSRF-Token': OM.csrf, Accept: 'application/json' };
  let payload;
  if (body instanceof Blob || body instanceof ArrayBuffer) payload = body;
  else if (body !== undefined) { headers['Content-Type'] = 'application/json'; payload = JSON.stringify(body); }
  let res;
  try {
    res = await fetch('/api/v1/' + path.replace(/^\//, ''), { method, headers, body: payload, credentials: 'same-origin', signal: opts.signal });
  } catch (e) {
    if (e.name === 'AbortError') throw e;
    throw new ApiError(0, 'network', e.message);
  }
  if (res.status === 401) { location.href = '/login.php?next=' + encodeURIComponent(location.pathname + location.hash); throw new ApiError(401, 'unauthorized', ''); }
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new ApiError(res.status, data.error?.code || 'error', data.error?.message || res.statusText, data.error || {});
  return data;
}

export const thumbUrl = (id) => `/api/v1/assets/${id}/thumb`;
export const previewUrl = (id) => `/api/v1/assets/${id}/preview`;
export const videoUrl = (id) => `/api/v1/assets/${id}/video`;
export const originalUrl = (id) => `/api/v1/assets/${id}/original`;

export function qs(params) {
  const p = new URLSearchParams();
  for (const [k, v] of Object.entries(params)) if (v !== undefined && v !== null && v !== '') p.set(k, v);
  const s = p.toString();
  return s ? '?' + s : '';
}

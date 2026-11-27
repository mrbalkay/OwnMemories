"""App Store Connect API için ortak, bağımlılıksız istemci (ES256 JWT)."""
import base64, json, os, subprocess, time, urllib.error, urllib.request

API = "https://api.appstoreconnect.apple.com/v1"


def b64url(b: bytes) -> str:
    return base64.urlsafe_b64encode(b).rstrip(b"=").decode()


def der_to_raw(sig: bytes) -> bytes:
    # ECDSA DER (SEQUENCE{INTEGER r, INTEGER s}) -> r||s (32+32)
    assert sig[0] == 0x30
    i = 2 if sig[1] < 0x80 else 2 + (sig[1] & 0x7F)
    out = b""
    for _ in range(2):
        assert sig[i] == 0x02
        ln = sig[i + 1]
        v = sig[i + 2 : i + 2 + ln].lstrip(b"\x00")
        out += v.rjust(32, b"\x00")
        i += 2 + ln
    return out


def token() -> str:
    header = {"alg": "ES256", "kid": os.environ["ASC_KEY_ID"], "typ": "JWT"}
    now = int(time.time())
    payload = {"iss": os.environ["ASC_ISSUER_ID"], "iat": now, "exp": now + 1000, "aud": "appstoreconnect-v1"}
    signing_input = (b64url(json.dumps(header).encode()) + "." + b64url(json.dumps(payload).encode())).encode()
    der = subprocess.run(
        ["openssl", "dgst", "-sha256", "-sign", os.environ["ASC_KEY_PATH"]],
        input=signing_input, capture_output=True, check=True,
    ).stdout
    return signing_input.decode() + "." + b64url(der_to_raw(der))


TOKEN = token()


def call(method, path, body=None):
    req = urllib.request.Request(
        API + path, method=method,
        data=json.dumps(body).encode() if body is not None else None,
        headers={"Authorization": "Bearer " + TOKEN, "Content-Type": "application/json"},
    )
    try:
        with urllib.request.urlopen(req, timeout=30) as r:
            raw = r.read()
            return r.status, (json.loads(raw) if raw else {})
    except urllib.error.HTTPError as e:
        raw = e.read()
        try:
            return e.code, json.loads(raw)
        except Exception:
            return e.code, {"raw": raw.decode(errors="replace")}


def errtext(resp):
    return "; ".join(x.get("detail") or x.get("title", "") for x in resp.get("errors", [])) or json.dumps(resp)[:300]



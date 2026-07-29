# API Documentation

The server exposes a single ingest endpoint used by the ESP8266 firmware.

## Endpoint

```
POST /api/receive.php
Content-Type: application/json
```

Optional authentication (if `DEVICE_KEY` is set in `includes/config.php`):

```
X-Device-Key: your-secret-key
```

---

## Payload — schema v1

```json
{
  "temperature": 21.45,
  "rssi": -61,
  "boot_count": 12,
  "fails": 0,

  "schema": 1,
  "device": "TempMonitor",
  "fw": "3.0.0",
  "sdk": "2.2.2-dev(38a443e)",
  "chip_id": "DC8109",
  "mac": "EC:64:C9:DC:81:09",
  "ip": "10.1.1.11",

  "uptime_s": 86412,
  "millis": 86412345,

  "temp_c": 21.45,
  "adc_raw": 512,
  "adc_samples": 32,
  "spike_rejects": 3,

  "channel": 6,
  "bssid": "F8:AA:3F:15:DD:E6",
  "wifi_state": "connected",
  "wifi_reconnects": 4,

  "consec_fails": 0,
  "total_uploads": 140,
  "ok_uploads": 139,
  "fail_uploads": 1,
  "ota_updates": 2,

  "heap": 40344,
  "heap_min": 40256,

  "reset_reason": "Software/System restart",
  "last_error": ""
}
```

### Field reference

| Field | Type | Description |
|---|---|---|
| `temperature`, `temp_c` | float | Temperature in °C (identical; both sent for legacy compat) |
| `rssi` | int | WiFi signal strength, dBm |
| `boot_count` | uint32 | Boots since power-on (RTC memory — resets on power loss) |
| `fails` / `consec_fails` | uint32 | Consecutive failed uploads; resets to 0 on success |
| `schema` | int | Payload version. Currently `1`. Absent = legacy payload |
| `device`, `fw`, `sdk` | string | Identity and firmware metadata |
| `chip_id` | string | **Unique device key** used by the server |
| `mac`, `ip`, `channel`, `bssid`, `wifi_state` | – | Network diagnostics |
| `uptime_s`, `millis` | uint32 | Runtime (device reboots every 24 h by design) |
| `adc_raw`, `adc_samples`, `spike_rejects` | int | Sensor quality diagnostics |
| `wifi_reconnects` | uint32 | Reconnects since power-on |
| `total_uploads`, `ok_uploads`, `fail_uploads` | uint32 | Upload stats since power-on |
| `ota_updates` | uint32 | OTA flashes performed |
| `heap`, `heap_min` | uint32 | Current / lowest free RAM in bytes |
| `reset_reason` | string | e.g. `Power on`, `Software/System restart`, `Exception` |
| `last_error` | string | Last device-side error; empty when healthy |

---

## Legacy payload

Older firmware sends only:

```json
{"temperature": 21.45, "rssi": -61, "boot_count": 12, "fails": 0}
```

Still accepted. Devices without a `chip_id` are assigned a stable ID derived from
their source IP (`legacy-xxxxxxxx`).

---

## Response contract

**The firmware validates the response body, not just the HTTP status code.**

| Server response | Firmware treats as |
|---|---|
| `200` + body containing `ok`, `success`, or empty body | ✅ Success |
| Body containing the word `error` | ❌ Failure → exponential backoff (10 s → 15 min) |

### Success

```json
{"status": "ok", "reading_id": 123}
```

### Failure

```json
{"status": "error", "message": "invalid json"}
```

| HTTP code | Meaning |
|---|---|
| 200 | Accepted |
| 400 | Malformed JSON / oversized body |
| 403 | Missing or wrong `X-Device-Key` |
| 500 | Server-side error (DB, etc.) |

---

## Examples

```bash
# Legacy
curl -i -X POST http://your-server/api/receive.php \
  -H "Content-Type: application/json" \
  -d '{"temperature":21.45,"rssi":-61,"boot_count":1,"fails":0}'

# Schema v1 (abbreviated)
curl -i -X POST http://your-server/api/receive.php \
  -H "Content-Type: application/json" \
  -d '{"schema":1,"chip_id":"DC8109","temp_c":21.45,"rssi":-61,"heap":40344}'
```

Both return `{"status":"ok",...}` with HTTP 200.
```
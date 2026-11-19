# AttendanceAPI - FINEA / ZKTeco ADMS

## Overview

This module integrates ZKTeco biometric devices, such as ProFace and Face 200, with the FINEA RH environment through the ADMS push protocol.

Flow:

```text
ZKTeco device -> /iclock ADMS endpoints -> attendance database -> secured REST API -> test UI / FINEA RH
```

The ADMS ingestion endpoints remain open for the devices. The REST API and test UI are protected and require a valid FINEA RH or Admin account before attendance data can be read or linked.

## Features

- Real-time attendance capture from ZKTeco ADMS.
- Device and raw event tracking.
- Automatic database setup and schema updates.
- Secured REST API for attendance data.
- FINEA RH/Admin authentication for API reads.
- Test UI with FINEA login.
- FINEA personnel snapshot inside the attendance database.
- Manual personnel mapping from detected device PINs to FINEA personnel.
- Best-effort automatic mapping when the ZKTeco PIN matches the FINEA personnel matricule.
- Automatic device date/time sync in GMT+00 through the ADMS `getrequest` command channel.

## Project Structure

```text
attendance/
  .env.example
  AttendanceAPI.md
  config/
    database.php
    env.php
    ensure_tables.php
    finea_auth.php
    finea_database.php
    personnel_sync.php
  iclock/
    router.php
  api/
    attendance/
      index.php
      test-ui.php
```

## Environment Configuration

Create a local `.env` file from `.env.example`.

```env
ATTENDANCE_DB_HOST=localhost
ATTENDANCE_DB_NAME=fidestci_attendance_db
ATTENDANCE_DB_USER=fidestci_ulrich
ATTENDANCE_DB_PASS=

FINEA_DB_HOST=localhost
FINEA_DB_NAME=fidestci_projet_db
FINEA_DB_USER=fidestci_ulrich
FINEA_DB_PASS=

ATTENDANCE_FINEA_EMAIL=
ATTENDANCE_FINEA_PASSWORD=
ATTENDANCE_DEVICE_TIME_SYNC_INTERVAL_MINUTES=30
ATTENDANCE_DEVICE_SETTIME_OFFSET_MINUTES=0
```

`ATTENDANCE_FINEA_EMAIL` and `ATTENDANCE_FINEA_PASSWORD` are optional server-to-server client credentials. The account must exist in FINEA, be active, and have RH or Admin access.

## Device Configuration

On the ZKTeco device:

```text
Menu -> Communication -> Cloud Server Configuration
```

Recommended values:

```text
Server Mode: ADMS
Enable Domain Name: ON
Server Address: attendance.fidest.ci
HTTPS: ON
Proxy: OFF
Port: automatic / 443
```

Fallback for non-HTTPS testing:

```text
HTTPS: OFF
Port: 80
```

## ADMS Endpoints

The device sends plain text payloads, not JSON.

### `POST /iclock/cdata`

Main ingestion endpoint.

Supported tables:

| Table | Description |
| --- | --- |
| `ATTLOG` | Attendance logs |
| `OPERLOG` | Operations and user updates |
| `USER` | User data, when sent by the device |
| `FP` | Fingerprint templates, currently stored only as raw events |
| `FACE` | Face templates, currently stored only as raw events |

Expected response:

```text
OK
```

### `GET /iclock/getrequest`

Heartbeat / polling endpoint.

This endpoint also sends pending device commands. For automatic time sync, the server can answer:

```text
SETTIME 2026-04-29 14:10:00
```

The time is generated in GMT+00. To avoid resetting the clock on every polling request, the server sends at most one automatic `SETTIME` per device within `ATTENDANCE_DEVICE_TIME_SYNC_INTERVAL_MINUTES`.

Some ZKTeco firmwares apply their own timezone offset after receiving `SETTIME`. If the terminal displays one hour ahead after receiving a GMT+00 command, set:

```env
ATTENDANCE_DEVICE_SETTIME_OFFSET_MINUTES=-60
```

This makes the server send one hour less so the terminal display lands on GMT+00.

Default response when there is no pending command:

```text
OK
```

### `GET /iclock/devicecmd`

Device command feedback endpoint.

Expected response:

```text
OK
```

## Authentication

The REST API protects all data routes. Only `health`, `login`, and `logout` are callable without an existing session.

A user is authorized when the FINEA account:

- exists in `user_devis_banamur`
- has `active = 1`
- has `acces_rh = 1` or `acces_admin = 1`, or a role label containing `rh` or `admin`

Supported authentication modes:

- Browser session created by `POST /api/attendance/index.php?route=login`
- HTTP Basic Auth
- Headers `X-FINEA-EMAIL` and `X-FINEA-PASSWORD`

Example Basic Auth:

```bash
curl -u "rh@example.com:password" "https://attendance.fidest.ci/api/attendance/index.php?route=logs&limit=50"
```

Example custom headers:

```bash
curl \
  -H "X-FINEA-EMAIL: rh@example.com" \
  -H "X-FINEA-PASSWORD: password" \
  "https://attendance.fidest.ci/api/attendance/index.php?route=devices"
```

Unauthenticated data requests return HTTP `401`:

```json
{
  "success": false,
  "message": "Authentification FINEA RH/Admin requise",
  "auth_required": true
}
```

## REST API

Base path:

```text
/api/attendance/index.php
```

### Health Check

Public route.

```http
GET /api/attendance/index.php?route=health
```

Returns API status and whether optional `.env` client credentials are configured.

### Login

Creates a browser session for the test UI.

```http
POST /api/attendance/index.php?route=login
Content-Type: application/json
```

Body:

```json
{
  "email": "rh@example.com",
  "password": "password"
}
```

### Logout

```http
POST /api/attendance/index.php?route=logout
```

### Current User

```http
GET /api/attendance/index.php?route=me
```

Requires authentication.

### Devices

```http
GET /api/attendance/index.php?route=devices
```

Requires authentication.

### Device Commands

```http
GET /api/attendance/index.php?route=device-commands&limit=50
```

Optional filter:

```http
GET /api/attendance/index.php?route=device-commands&device_sn=AEXH223360098&limit=50
```

Returns queued and executed ADMS commands.

### Rename / Locate Device

```http
POST /api/attendance/index.php?route=update-device
Content-Type: application/json
```

Body:

```json
{
  "device_sn": "AEXH223360098",
  "name": "Accueil principal",
  "location": "Siege / entree RH"
}
```

This updates the local terminal metadata used by the test UI.

### Program Device Time Sync

```http
POST /api/attendance/index.php?route=sync-device-time
Content-Type: application/json
```

Body:

```json
{
  "device_sn": "AEXH223360098"
}
```

This queues a `SETTIME YYYY-MM-DD HH:MM:SS` command in GMT+00. The command is sent the next time the device calls `/iclock/getrequest`.

### Attendance Logs

```http
GET /api/attendance/index.php?route=logs&limit=50
```

Requires authentication.

`limit` is capped at `200`.

### Detected Device Employees

```http
GET /api/attendance/index.php?route=employees
```

Returns users detected from the device and their current FINEA mapping.

### FINEA Personnel Snapshot

```http
GET /api/attendance/index.php?route=finea-personnel&limit=200
```

Optional search:

```http
GET /api/attendance/index.php?route=finea-personnel&q=kouassi&limit=200
```

This route automatically initializes or refreshes the snapshot when it is empty or stale.

### Synchronize FINEA Personnel

```http
POST /api/attendance/index.php?route=sync-finea-personnel
```

Reads personnel from the FINEA RH database and upserts the local attendance snapshot.

Response:

```json
{
  "success": true,
  "message": "Personnel FINEA synchronise",
  "count": 86
}
```

### Map Device PIN To FINEA Personnel

```http
POST /api/attendance/index.php?route=map-personnel
Content-Type: application/json
```

Body:

```json
{
  "device_sn": "AEXH223360098",
  "zkteco_pin": "1",
  "personnel_id": 12
}
```

The route updates:

- `attendance_personnel_map`
- existing `attendance_logs.personnel_id` rows for the same `device_sn` and `zkteco_pin`

## Test Interface

URL:

```text
/api/attendance/test-ui.php
```

The UI now requires a FINEA RH/Admin login before displaying data.

Main actions:

- Check API health.
- View devices.
- Open a separate terminal management window.
- Rename terminals and define their location.
- Program GMT+00 device time sync.
- View latest attendance logs.
- Synchronize FINEA personnel.
- View detected device employees.
- Link a ZKTeco PIN to a FINEA personnel record.
- Logout.

## Database Schema

Tables are created or updated automatically by `config/ensure_tables.php`.

### `attendance_devices`

Stores registered ZKTeco devices.

| Field | Description |
| --- | --- |
| `serial_number` | Device serial number |
| `name` | Optional device name |
| `location` | Optional location |
| `last_seen_at` | Last device communication |

### `attendance_logs`

Stores attendance events.

| Field | Description |
| --- | --- |
| `device_sn` | Device serial number |
| `zkteco_pin` | Device user PIN |
| `personnel_id` | Linked FINEA personnel ID, when available |
| `received_at` | Server GMT+0 receipt timestamp used by FINEA as the official attendance time |
| `punch_time` | Raw timestamp sent by the device, kept for audit and drift checks |
| `device_punch_time` | Optional alias/copy of the raw device timestamp when the API stores both names |
| `verify_mode` | ZKTeco verification mode |
| `punch_state` | `0` entry, `1` exit, other values depend on device config |
| `work_code` | Optional work code |
| `raw_line` | Original raw line |

### `attendance_personnel_map`

Maps device users to FINEA personnel.

| Field | Description |
| --- | --- |
| `device_sn` | Device serial number |
| `zkteco_pin` | Device user PIN |
| `personnel_id` | FINEA personnel ID |
| `personnel_matricule` | Snapshot of FINEA matricule |
| `personnel_name` | Snapshot of FINEA name |
| `personnel_email` | Snapshot of FINEA email |
| `display_name` | Name received from the device |
| `linked_at` | Last mapping time |

### `attendance_finea_personnel`

Local snapshot of FINEA RH personnel.

| Field | Description |
| --- | --- |
| `personnel_id` | FINEA `personnel.id_personnel_soignant` |
| `matricule` | FINEA personnel matricule |
| `full_name` | FINEA personnel name |
| `email` | FINEA personnel email |
| `phone` | FINEA personnel phone |
| `service_label` | Service label |
| `fonction_label` | Function label |
| `is_active` | Active/in-post flag |
| `synced_at` | Last sync time |

### `attendance_raw_events`

Stores raw requests for debugging and audit.

### `attendance_device_commands`

Stores commands waiting to be pulled by ZKTeco devices.

| Field | Description |
| --- | --- |
| `device_sn` | Target device serial number |
| `command_type` | Command category, for example `settime` |
| `command` | Raw ADMS command, for example `SETTIME 2026-04-29 14:10:00` |
| `executed` | `0` pending, `1` sent to device |
| `executed_at` | UTC send time |
| `response_body` | Optional `/devicecmd` response body |

## Data Format Notes

ZKTeco sends text lines. Example `ATTLOG` line:

```text
1    2026-04-28 13:55:22    0    1
```

Parsed columns:

| Column | Meaning |
| --- | --- |
| `1` | PIN |
| `2026-04-28 13:55:22` | Punch datetime |
| `0` | Verify mode |
| `1` | Punch state |

Invalid or incomplete lines are ignored.

FINEA uses `received_at` as the official attendance datetime when it is available. The raw device datetime remains available through `punch_time` and/or `device_punch_time`, so a device with an incorrect clock cannot shift attendance records away from the real collection time.

## Operational Notes

- Keep the device timezone aligned with Abidjan / GMT.
- Incorrect device time creates incorrect attendance records.
- The ADMS endpoints must stay reachable by the device.
- The REST API should remain HTTPS-only in production.
- The FINEA personnel snapshot can be refreshed from the test UI or through `sync-finea-personnel`.
- Device clock sync is sent through `/iclock/getrequest` in GMT+00 and throttled per device.

## Status

- ADMS integration working.
- Real-time attendance ingestion working.
- REST API secured with FINEA RH/Admin authentication.
- Test UI secured with login.
- FINEA personnel snapshot implemented.
- Personnel mapping implemented.
- ZKTeco device time sync implemented with queued `SETTIME` commands.
- Automatic schema creation and updates implemented.

# ARCHI Partner API: Trump Tower, King Tamar, Sioni Lake

Read-only REST API on ARCHI's CRM. It covers three projects: **Trump Tower**, **King Tamar** and **Sioni Lake**. For every unit in them (apartments, parking, commercial and office units) it returns availability, base price and sales status. The data is live: each call reads the current state of the catalogue.

## Access

You get one webhook URL from ARCHI, referred to below as `<WEBHOOK_URL>`. It looks like `https://crm.archi.ge/rest/<user>/<key>/`. Keep it secret: anyone with the URL can call the API.

The webhook can call only the two methods below. Any other CRM method returns `401 insufficient_scope`.

Add the method name and `.json` to the base URL. Both `GET` (parameters in the query string) and `POST` (JSON body, `Content-Type: application/json`) work. Every successful response is HTTP 200 with the payload under `result`.

## Projects and IDs

| `project_id` | Project | Blocks (`block_id`: name) |
|---|---|---|
| 1 | Trump Tower | 596: Trump Tower |
| 2 | King Tamar | 570: King Tamar |
| 3 | Sioni Lake | 571: A, 555: B, 586: C, 582: E |

## 1. `archi.partner.project.list`

Lists the three projects and their blocks, with unit counts per status. It takes no parameters, and the response is cached for up to 5 minutes. The example below is shortened and its counts are illustrative.

```bash
curl -s -X POST '<WEBHOOK_URL>archi.partner.project.list.json' -H 'Content-Type: application/json' -d '{}'
```

```json
{
  "result": {
    "projects": [
      {
        "id": 3,
        "name": "Sioni Lake",
        "units": {"total": 382, "available": 273, "reserved": 2, "sold": 98, "not_in_sale": 9, "no_price": 0, "unknown": 0},
        "blocks": [
          {"id": 571, "name": "A", "units": {"total": 75, "available": 38, "reserved": 0, "sold": 37, "not_in_sale": 0, "no_price": 0, "unknown": 0}}
        ]
      }
    ],
    "count": 3,
    "generated_at": "2026-11-20T12:00:00+04:00"
  }
}
```

## 2. `archi.partner.unit.list`

Lists units page by page. All parameters are optional. Without `project_id` or `block_id`, it returns the units of all three projects.

| Parameter | Type | Description |
|---|---|---|
| `project_id` | int | `1`, `2` or `3`: units of one project, including all of its blocks. |
| `block_id` | int | Units of one block (see the table above). If both are sent, `block_id` is used. |
| `status` | string or array | `available`, `reserved`, `sold`, `not_in_sale`, `no_price`. For example `"available"` or `["available","reserved"]`. In a GET query string: `status[]=available&status[]=reserved`. |
| `type` | string | `apartment`, `parking`, `commercial`, `office`, `other`. |
| `after_id` | int | Cursor: return units with `id` greater than this. Start with `0` (default). |
| `limit` | int | Page size, 1–500. Default is 200. |

```bash
curl -s -X POST '<WEBHOOK_URL>archi.partner.unit.list.json' -H 'Content-Type: application/json' \
  -d '{"project_id": 2, "status": ["available","reserved"], "type": "apartment", "limit": 200}'
```

```json
{
  "result": {
    "units": [
      {
        "id": 4506472,
        "number": "2",
        "project_id": 2,
        "project_name": "King Tamar",
        "block_id": 570,
        "block_name": "King Tamar",
        "type": "apartment",
        "type_label": "ბინა",
        "floor": 7,
        "rooms": 3,
        "area_total_m2": 152.1,
        "area_inside_m2": 83.8,
        "area_summer_m2": 68.3,
        "entrance": null,
        "view": {"ka": null, "en": null, "ru": null},
        "status": "available",
        "price_usd": 436278,
        "price_per_m2_usd": 3496.33,
        "updated_at": "2026-07-10T17:17:31+04:00"
      }
    ],
    "count": 1,
    "next_after_id": null
  }
}
```

- **Prices.** `price_usd` is the base list price in USD. **Sold units never have a price:** `price_usd` and `price_per_m2_usd` are `null` when `status` is `sold`. A price is also `null` when it isn't set in the catalogue.
- **Empty fields.** Any field may be `null` when ARCHI hasn't filled it in, for example `rooms` or `view` on parking.
- **`type_label`.** The unit type as written in ARCHI's catalogue, in Georgian.
- **`updated_at`.** Informational only. Use full syncs, described below, rather than incremental sync on this field.

### Paging

Order is by `id`, ascending. Repeat the call with `after_id` set to the previous `next_after_id` until `next_after_id` is `null`.

```python
import requests
url = WEBHOOK_URL + "archi.partner.unit.list.json"
after, units = 0, []
while True:
    r = requests.post(url, json={"after_id": after, "limit": 500}, timeout=60).json()["result"]
    units += r["units"]
    if r["next_after_id"] is None:
        break
    after = r["next_after_id"]
```

All three projects together have about 1,700 units, which is 4 calls of 500. A full sync every 15–60 minutes is plenty. Please don't run it more often than every 5 minutes.

## Errors

On an error the HTTP status is not 200, and the body is `{"error": "<CODE>", "error_description": "<text>"}`.

| HTTP | `error` | When |
|---|---|---|
| 400 | `INVALID_STATUS`, `INVALID_TYPE`, `INVALID_PROJECT_ID`, `INVALID_BLOCK_ID` | A parameter has a wrong value. |
| 404 | `NOT_FOUND` | A `project_id` or `block_id` that isn't in the table above. |
| 401 | `insufficient_scope` / `invalid_token` | The method isn't part of this API, or the webhook URL is wrong or revoked. |

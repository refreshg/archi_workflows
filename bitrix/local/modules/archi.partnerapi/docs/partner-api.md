# ARCHI Partner API: projects, units, prices, statuses

Read-only REST API on ARCHI's CRM (Bitrix24, self-hosted). It returns ARCHI's projects and every unit in them (apartments, parking, commercial and office units), with availability, base price and sales status. The data is live: each call reads the current state of the catalogue.

## Access

You get one webhook URL from ARCHI, referred to below as `<WEBHOOK_URL>`. It looks like `https://crm.archi.ge/rest/<user>/<key>/`. Keep it secret: anyone with the URL can call the API.

The webhook can call only the two methods below. Any other CRM method returns `401 insufficient_scope`.

Call a method with `POST <WEBHOOK_URL><method>.json` and a JSON body (`Content-Type: application/json`). Every successful response is HTTP 200 with the payload under `result`.

```bash
curl -s -X POST '<WEBHOOK_URL>archi.partner.project.list.json' -H 'Content-Type: application/json' -d '{}'
```

## 1. `archi.partner.project.list`

Lists all projects with their blocks and unit counts per status. It takes no parameters, and the response is cached for up to 5 minutes.

```json
{
  "result": {
    "projects": [
      {
        "id": 2217206,
        "kind": "complex",
        "name":     {"ka": "არქი დიღომი", "en": "Archi Dighomi", "ru": "Арки Дигоми"},
        "city":     {"ka": "თბილისი", "en": "Tbilisi", "ru": "Тбилиси"},
        "district": {"ka": "დიდი დიღომი", "en": "Didi Digomi", "ru": "Диди Дигоми"},
        "address":  {"ka": "პეტრე იბერის 16ა", "en": "Petre Iberi Street 16A", "ru": "Петре Ибери 16а"},
        "status": "completed",
        "completion_date": null,
        "show_on_broker_site": null,
        "units": null,
        "blocks": [
          {
            "id": 2217202, "kind": "building",
            "name": {"ka": "C", "en": "C", "ru": "C"},
            "status": "completed",
            "units": {"total": 103, "available": 7, "reserved": 0, "sold": 96, "not_in_sale": 0, "no_price": 0, "unknown": 0}
          }
        ]
      }
    ],
    "count": 45,
    "generated_at": "2026-11-20T12:00:00+04:00"
  }
}
```

| Field | Meaning |
|---|---|
| `id` | Project or block ID. Pass it to `archi.partner.unit.list` as `project_id` or `block_id`. |
| `kind` | `complex`: a project made of blocks, listed in `blocks`. `independent_building`: a single-building project with no blocks; its units hang on the project itself. `building`: a block of a complex. |
| `status` | `ongoing`, `completed`, `new`, or `null`. |
| `completion_date` | `YYYY-MM-DD`, or `null` if not set. |
| `units` | Unit counts by status for this project or block. `null` means no units are published for it; for a complex, the counts are on its blocks. |

## 2. `archi.partner.unit.list`

Lists units page by page. All parameters are optional.

| Parameter | Type | Description |
|---|---|---|
| `project_id` | int | Units of one project, including all of its blocks. |
| `block_id` | int | Units of one block. If both are sent, `block_id` is used. |
| `status` | string or array | `available`, `reserved`, `sold`, `not_in_sale`, `no_price`. For example `"available"` or `["available","reserved"]`. |
| `type` | string | `apartment`, `parking`, `commercial`, `office`, `other`. |
| `after_id` | int | Cursor: return units with `id` greater than this. Start with `0` (default). |
| `limit` | int | Page size, 1–500. Default is 200. |

```bash
curl -s -X POST '<WEBHOOK_URL>archi.partner.unit.list.json' -H 'Content-Type: application/json' \
  -d '{"project_id": 2217270, "status": ["available","reserved"], "type": "apartment", "limit": 200}'
```

```json
{
  "result": {
    "units": [
      {
        "id": 1554977,
        "number": "2",
        "project_id": 2217270,
        "block_id": 2217270,
        "block_name": "არქი დიალოგი კოხტა",
        "type": "apartment",
        "type_label": "ბინა",
        "floor": 1,
        "rooms": 2,
        "area_total_m2": 37.2,
        "area_inside_m2": 36.3,
        "area_summer_m2": 0.9,
        "entrance": null,
        "view": {"ka": null, "en": null, "ru": null},
        "status": "available",
        "price_usd": 77362.4,
        "price_per_m2_usd": 2052.8,
        "updated_at": "2026-11-19T16:56:37+04:00"
      }
    ],
    "count": 1,
    "next_after_id": null
  }
}
```

- **Prices.** `price_usd` is the base list price in USD. **Sold units never have a price:** `price_usd` and `price_per_m2_usd` are `null` when `status` is `sold`. A price is also `null` when it isn't set in the catalogue.
- **Empty fields.** Any field may be `null` when ARCHI hasn't filled it in, for example `rooms` or `view` on parking.
- **`status: "unknown"`.** The unit has no status set in the catalogue. This is a small number of units.
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

A full sync of all units (about 30,000) takes about 60 calls of 500 and finishes in seconds. A full sync every 15–60 minutes is plenty. Please don't run it more often than every 5 minutes.

## Errors

On an error the HTTP status is not 200, and the body is `{"error": "<CODE>", "error_description": "<text>"}`.

| HTTP | `error` | When |
|---|---|---|
| 400 | `INVALID_STATUS`, `INVALID_TYPE`, `INVALID_PROJECT_ID`, `INVALID_BLOCK_ID` | A parameter has a wrong value. |
| 404 | `NOT_FOUND` | Unknown `project_id` or `block_id`. |
| 401 | `insufficient_scope` / `invalid_token` | The method isn't part of this API, or the webhook URL is wrong or revoked. |

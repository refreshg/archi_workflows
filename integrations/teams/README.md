# Teams შეტყობინებები (Microsoft Graph)

ARCHI-ს თანამშრომლებს პირად Teams ჩატში უგზავნის შეტყობინებას ანგარიშიდან **CRM Notification** (`crmnotification@archi.ge`).

## რა არის მზად

| რა | მდგომარეობა |
|---|---|
| Azure App registration **„CRM Teams“** | Tenant `8c44e26b-79c8-4a0a-9611-20b60700e1f3`, Client ID `c48fddac-4ca5-43d4-ad04-ed283e54e4f3` |
| უფლებები (Delegated, admin consent მიცემული) | `Chat.Create`, `ChatMessage.Send`, `User.Read.All`, `offline_access` |
| Authentication → Allow public client flows | **Yes** — Client Secret არ სჭირდება |
| გამგზავნი ანგარიში | `crmnotification@archi.ge` (Teams-ით) — ტესტი გავიდა 2026-10-20 |

## დაყენება (ერთხელ)

1. Node.js 18+ (გამოიყენება ჩაშენებული `fetch`, დამატებითი პაკეტი არ სჭირდება).
2. რეპოს root-ში `.env` (ნიმუში — `.env.example`):
   ```
   MS_TENANT_ID=8c44e26b-79c8-4a0a-9611-20b60700e1f3
   MS_CLIENT_ID=c48fddac-4ca5-43d4-ad04-ed283e54e4f3
   MS_REFRESH_TOKEN=
   ```
   `.env` git-ში არ იტვირთება.
3. გამგზავნის შესვლა:
   ```
   node integrations/teams/login.js
   ```
   Incognito ფანჯარაში გახსენით მითითებული ბმული, ჩაწერეთ კოდი და შედით **crmnotification@archi.ge**-ით.
   `MS_REFRESH_TOKEN` ავტომატურად ჩაიწერება `.env`-ში.

## გამოყენება

ბრძანების ხაზიდან:
```
node integrations/teams/send.js a.uchaneishvili@archi.ge "<b>ახალი დილი</b><br>https://crm.archi.ge/crm/deal/details/123/"
```

კოდიდან:
```js
const { sendTeamsMessage } = require('./integrations/teams/send');
await sendTeamsMessage('a.uchaneishvili@archi.ge', '<b>ახალი დილი</b><br>...');
```

ტექსტი HTML-ია (`<b>`, `<br>`, `<a href>`). მიმღები — ნებისმიერი `@archi.ge` მომხმარებელი Teams-ით.

## მნიშვნელოვანი

- `MS_REFRESH_TOKEN` ყოველ გამოყენებაზე იცვლება და `send.js` ახალს `.env`-ში წერს — ერთი ტოკენი ორ სხვადასხვა სერვერზე ერთდროულად არ გამოიყენოთ (თითოეულმა თავად გაუშვას `login.js`).
- ხელახალი შესვლა საჭიროა, თუ crmnotification-ს პაროლი შეეცვლება, ადმინი სესიებს გააუქმებს ან ტოკენი 90 დღე არ გამოიყენება.
- Graph-ის ლიმიტი: ერთი გამგზავნიდან რამდენიმე მესიჯი წამში — მასობრივი გაგზავნისას პაუზა ჩადეთ.

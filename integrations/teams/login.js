// One-time sign-in of the sender account (device code). Saves MS_REFRESH_TOKEN into .env, never prints it.
// Usage: node integrations/teams/login.js  → open the printed URL in an incognito window, enter the code,
// sign in as crmnotification@archi.ge.
const { load, set } = require('./env');

const SCOPE = 'offline_access User.Read User.Read.All Chat.Create ChatMessage.Send';

(async () => {
  const env = load();
  const base = `https://login.microsoftonline.com/${env.MS_TENANT_ID}/oauth2/v2.0`;
  const dc = await (await fetch(base + '/devicecode', {
    method: 'POST', body: new URLSearchParams({ client_id: env.MS_CLIENT_ID, scope: SCOPE }),
  })).json();
  if (!dc.device_code) throw new Error(`devicecode: ${dc.error} ${dc.error_description || ''}`);
  console.log(`Open ${dc.verification_uri} and enter code ${dc.user_code} (valid ${Math.round(dc.expires_in / 60)} min)`);

  const until = Date.now() + dc.expires_in * 1000;
  while (Date.now() < until) {
    await new Promise(r => setTimeout(r, (dc.interval || 5) * 1000));
    // The app registration is a public client ("Allow public client flows" = Yes), so no client secret is sent.
    const t = await (await fetch(base + '/token', {
      method: 'POST',
      body: new URLSearchParams({ grant_type: 'urn:ietf:params:oauth:grant-type:device_code', client_id: env.MS_CLIENT_ID, device_code: dc.device_code }),
    })).json();
    if (t.error === 'authorization_pending' || t.error === 'slow_down') continue;
    if (!t.access_token) throw new Error(`token: ${t.error} ${(t.error_description || '').split('\r\n')[0]}`);

    const me = await (await fetch('https://graph.microsoft.com/v1.0/me?$select=displayName,userPrincipalName', {
      headers: { Authorization: 'Bearer ' + t.access_token },
    })).json();
    set('MS_REFRESH_TOKEN', t.refresh_token);
    console.log(`Signed in as ${me.displayName} <${me.userPrincipalName}>. MS_REFRESH_TOKEN saved to .env.`);
    return;
  }
  throw new Error('code expired — run again');
})().catch(e => { console.error('ERROR', e.message); process.exit(1); });

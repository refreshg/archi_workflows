// Send a 1:1 Teams chat message as the signed-in sender (crmnotification@archi.ge).
// CLI:    node integrations/teams/send.js <recipient@archi.ge> "<html text>"
// Module: const { sendTeamsMessage } = require('./integrations/teams/send'); await sendTeamsMessage(email, html);
const { load, set } = require('./env');

const G = 'https://graph.microsoft.com/v1.0';
const SCOPE = 'offline_access User.Read User.Read.All Chat.Create ChatMessage.Send';

async function accessToken() {
  const env = load();
  if (!env.MS_REFRESH_TOKEN) throw new Error('MS_REFRESH_TOKEN missing — run login.js first');
  const t = await (await fetch(`https://login.microsoftonline.com/${env.MS_TENANT_ID}/oauth2/v2.0/token`, {
    method: 'POST',
    body: new URLSearchParams({ grant_type: 'refresh_token', client_id: env.MS_CLIENT_ID, refresh_token: env.MS_REFRESH_TOKEN, scope: SCOPE }),
  })).json();
  if (!t.access_token) throw new Error(`token: ${t.error} ${(t.error_description || '').split('\r\n')[0]}`);
  if (t.refresh_token) set('MS_REFRESH_TOKEN', t.refresh_token); // refresh tokens rotate — keep the newest
  return t.access_token;
}

async function sendTeamsMessage(to, html) {
  const token = await accessToken();
  const call = async (method, url, body) => {
    const r = await fetch(G + url, {
      method, headers: { Authorization: 'Bearer ' + token, 'Content-Type': 'application/json' }, body: body && JSON.stringify(body),
    });
    const j = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(`${method} ${url} -> ${r.status} ${j.error && j.error.code}: ${j.error && j.error.message}`);
    return j;
  };
  const me = await call('GET', '/me?$select=id,displayName,userPrincipalName');
  const user = await call('GET', `/users/${encodeURIComponent(to)}?$select=id,displayName,userPrincipalName`);
  // Creating a oneOnOne chat that already exists returns the existing one.
  const chat = await call('POST', '/chats', {
    chatType: 'oneOnOne',
    members: [me, user].map(u => ({
      '@odata.type': '#microsoft.graph.aadUserConversationMember', roles: ['owner'],
      'user@odata.bind': `https://graph.microsoft.com/v1.0/users('${u.id}')`,
    })),
  });
  const msg = await call('POST', `/chats/${chat.id}/messages`, { body: { contentType: 'html', content: html } });
  return { from: me.userPrincipalName, to: user.userPrincipalName, chatId: chat.id, messageId: msg.id, createdDateTime: msg.createdDateTime };
}

module.exports = { sendTeamsMessage };

if (require.main === module) {
  const [to, html] = process.argv.slice(2);
  if (!to || !html) { console.error('Usage: node send.js <recipient@archi.ge> "<html text>"'); process.exit(1); }
  sendTeamsMessage(to, html)
    .then(r => console.log(`SENT from ${r.from} to ${r.to} | message ${r.messageId} at ${r.createdDateTime}`))
    .catch(e => { console.error('ERROR', e.message); process.exit(1); });
}

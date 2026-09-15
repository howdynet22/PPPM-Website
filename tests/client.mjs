import assert from 'node:assert/strict';
const base=process.env.PPPM_TEST_URL;
if(!base)throw new Error('Set PPPM_TEST_URL to an isolated, seeded test server. This test changes fictional demo records.');
export class Client {
  cookie=''; token=''; user=null;
  async call(action,body,status=200,csrf=true) {
    const r=await fetch(`${base}/api.php?action=${action}`,{method:body?'POST':'GET',headers:{'Content-Type':'application/json',Cookie:this.cookie,...(csrf?{'X-CSRF-Token':this.token}:{})},...(body?{body:JSON.stringify(body)}:{})});
    if(r.headers.get('set-cookie'))this.cookie=r.headers.get('set-cookie').split(';')[0];
    const result=await r.json();assert.equal(r.status,status,`${action}: ${JSON.stringify(result)}`);
    if(result.csrfToken)this.token=result.csrfToken;
    return result;
  }
  async login(name){this.user=(await this.call('login',{email:`${name}@demo.pppm.test`,password:'password123'})).user;return this;}
}

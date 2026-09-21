/* Exercise actual PHP controllers with isolated WordPress fixtures; no server or real mail. */
'use strict';
const {execFileSync}=require('node:child_process');
const path=require('node:path');
const assert=require('node:assert/strict');
let checks=0;
function check(v,m){assert.ok(v,m);checks++;}
function request(input={}) {
    return JSON.parse(execFileSync('php',[path.join(__dirname,'customer-operations-smoke.php'),'request',JSON.stringify({order:1,nonce:'fixture-nonce',ops:{revision:''},operation:'save',intent:'00000000-0000-4000-8000-000000000099',...input})],{encoding:'utf8'}));
}
let r=request({nonce:'bad'});check(!r.success&&r.status===403,'AJAX nonce required');
r=request({_scenario:'no-staff'});check(!r.success,'buyer cannot edit staff operations');
r=request({_scenario:'disabled'});check(!r.success,'inactive module rejects AJAX');
r=request({order:999});check(!r.success,'foreign order denied');
r=request({_scenario:'stale'});check(!r.success&&r.data.message.includes('changed'),'optimistic concurrency');
r=request({operation:'unknown'});check(!r.success,'unknown operation rejected');
r=request({ops:{revision:'',items:{11:{serials:'A\nB'}}}});check(r.success,'item save endpoint');check(r.fixture.orders[1].items[11].meta._ffla_ops_item.serials.join(',')==='A,B','serials stored by controller');check(r.data.html.includes('A\nB'),'updated fragment contains saved serials');
r=request({ops:{revision:'',items:{11:{serials:'A\na'}}}});check(!r.success,'duplicate serial endpoint');check(!r.fixture.orders[1].items[11].meta._ffla_ops_item,'invalid save leaves items unchanged');
r=request({operation:'public',public_text:'Internal-free public update'});check(r.success,'explicit public update');check(r.fixture.orders[1].meta._ffla_ops.public[0].text==='Internal-free public update','public text persisted');check(r.fixture.sent.length===0,'public email is opt-in');
r=request({operation:'public',public_text:'Customer text',email_public:'1'});check(r.success&&r.fixture.sent.length===1,'explicit email sent');
r=request({operation:'public',public_text:'Customer text',email_public:'1',_scenario:'mail-off'});check(!r.success&&!r.fixture.orders[1].meta._ffla_ops,'mail master prevents partial public write');
r=request({operation:'public',public_text:'<script></script>'});check(!r.success,'empty sanitized public message rejected');
r=request({operation:'public',public_text:'Hello',intent:'bad-token'});check(!r.success,'invalid public action intent denied');
r=request({operation:'ready'});check(!r.success,'ready requires serials when configured');
r=request({operation:'collect'});check(!r.success,'non-ready collection denied');
r=request({operation:'collect',_scenario:'ready'});check(r.success&&r.fixture.orders[1].status==='completed','ready collection endpoint completes');check(r.data.reload,'status change requires reload of native form');
r=request({operation:'mail',_scenario:'ready'});check(r.success&&r.fixture.sent.length===1,'manual ready email');
r=request({operation:'upload'});check(!r.success,'upload requires actual valid multipart file');
r=request({_endpoint:'help',_wpnonce:'bad',message:'Help'});check(!r.success&&r.status===403,'help nonce required');
r=request({_endpoint:'help',_wpnonce:'fixture-nonce',message:'Help',_scenario:'other-user'});check(!r.success,'another buyer cannot request on order');
r=request({_endpoint:'help',_wpnonce:'fixture-nonce',message:'Help',_scenario:'help-repeat'});check(!r.success,'help requests rate limited');
r=request({_endpoint:'help',_wpnonce:'fixture-nonce',message:'Missing package',_scenario:'help-resolved'});check(r.success&&r.fixture.orders[1].meta._ffla_ops_case==='open','owner request reopens resolved case');check(r.fixture.orders[1].status==='processing','help leaves WC status alone');check(r.fixture.orders[1].notes[0][1]===false,'help text stored privately');
r=request({_endpoint:'template',operation:'preview',kind:'ready'});check(r.success&&r.fixture.sent.length===0&&r.data.message.includes('PREVIEW'),'template preview without mail');
r=request({_endpoint:'template',operation:'test',kind:'ready'});check(r.success&&r.fixture.sent[0].to==='staff@example.invalid','test only sends to current staff email');
r=request({_endpoint:'template',operation:'test',kind:'ready',_scenario:'mail-off'});check(!r.success&&r.fixture.sent.length===0,'test respects mail master');
console.log(checks+' Customer operations endpoint checks passed.');

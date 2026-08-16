{include file=$CA_HEADER_TEMPLATE}
<div class="ca-admin__panel"><h3>新建老师身份</h3>
<form method="post" class="ca-admin__form" autocomplete="off">
  <input type="hidden" name="pwg_token" value="{$CA_PWG_TOKEN|escape:'html'}"><input type="hidden" name="action" value="create_teacher">
  <label>Teacher ID<input name="roster_code" maxlength="64" placeholder="T-001" required></label>
  <label>真实姓名<input name="real_name" maxlength="190" required></label>
  <label>建立原因<input name="reason" maxlength="500" placeholder="教师名册导入 / 补录" required></label>
  <button type="submit">建立老师身份</button>
</form></div>
{if isset($CA_IDENTITY) && $CA_IDENTITY}
<div class="ca-admin__panel"><h3>{$CA_IDENTITY.roster_code|escape:'html'} · {$CA_IDENTITY.real_name|escape:'html'} <span class="ca-admin__badge">{$CA_IDENTITY.state|escape:'html'}</span></h3>
<table><thead><tr><th>席位</th><th>状态</th><th>账号</th><th>邮箱</th><th>注册时间</th></tr></thead><tbody>{foreach from=$CA_IDENTITY.seats item=seat}<tr><td>{$seat.seat_type|escape:'html'}</td><td>{$seat.state|escape:'html'}</td><td>{if $seat.username}{$seat.username|escape:'html'}{else}—{/if}</td><td>{if $seat.mail_address}{$seat.mail_address|escape:'html'}{else}—{/if}</td><td>{if $seat.bound_at}{$seat.bound_at|escape:'html'}{else}—{/if}</td></tr>{/foreach}</tbody></table>
<div class="ca-admin__actions"><form method="post" class="ca-admin__inline" autocomplete="off" data-confirm="签发新 Code 会立即废止旧 Code，确认继续？"><input type="hidden" name="pwg_token" value="{$CA_PWG_TOKEN|escape:'html'}"><input type="hidden" name="action" value="reissue_claim"><input type="hidden" name="identity_id" value="{$CA_IDENTITY.id|escape:'html'}"><label>签发 / 重发原因<input name="reason" maxlength="500" required></label><button type="submit">生成一次性 Teacher Claim</button></form>
{if $CA_IDENTITY.state == 'FROZEN'}<form method="post" class="ca-admin__inline"><input type="hidden" name="pwg_token" value="{$CA_PWG_TOKEN|escape:'html'}"><input type="hidden" name="action" value="unfreeze_identity"><input type="hidden" name="identity_id" value="{$CA_IDENTITY.id|escape:'html'}"><label>解除冻结原因<input name="reason" maxlength="500" required></label><button type="submit">解除冻结</button></form>{else}<form method="post" class="ca-admin__inline" data-confirm="冻结后该老师账号将立即失去 Class Archive 授权，确认继续？"><input type="hidden" name="pwg_token" value="{$CA_PWG_TOKEN|escape:'html'}"><input type="hidden" name="action" value="freeze_identity"><input type="hidden" name="identity_id" value="{$CA_IDENTITY.id|escape:'html'}"><label>冻结原因<input name="reason" maxlength="500" required></label><button class="ca-admin__danger" type="submit">冻结老师身份</button></form>{/if}</div></div>
{/if}
<div class="ca-admin__panel"><h3>老师身份</h3><table><thead><tr><th>Teacher ID</th><th>姓名</th><th>Claim 状态</th><th>账号状态</th><th>注册时间</th><th>最近登录 / 活动</th><th>操作</th></tr></thead><tbody>
{foreach from=$CA_TEACHERS item=identity}<tr><td>{$identity.roster_code|escape:'html'}</td><td>{$identity.real_name|escape:'html'}</td><td>{if $identity.registered_at}已认领{else}未认领{/if}</td><td>{$identity.state|escape:'html'} / {$identity.formal_seat_state|escape:'html'}</td><td>{if $identity.registered_at}{$identity.registered_at|escape:'html'}{else}—{/if}</td><td>{if $identity.last_activity}{$identity.last_activity|escape:'html'}{else}—{/if}</td><td><a href="{$CA_BASE_URL|escape:'html'}teachers&amp;identity_id={$identity.id|escape:'url'}">查看</a></td></tr>{foreachelse}<tr><td colspan="7">尚未建立老师身份。</td></tr>{/foreach}
</tbody></table></div></section>

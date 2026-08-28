{include file=$CA_HEADER_TEMPLATE}
<div class="ca-admin__panel"><h3>新建同学身份</h3>
<form method="post" class="ca-admin__form" autocomplete="off">
  <input type="hidden" name="pwg_token" value="{$CA_PWG_TOKEN|escape:'html'}"><input type="hidden" name="action" value="create_classmate">
  <label>同学编号<input name="roster_code" maxlength="64" placeholder="C25-001" required></label>
  <label>真实姓名<input name="real_name" maxlength="190" required></label>
  <label>建立原因<input name="reason" maxlength="500" placeholder="名册导入 / 补录" required></label>
  <button type="submit">建立身份与席位</button>
</form></div>
{if isset($CA_IDENTITY) && $CA_IDENTITY}
<div class="ca-admin__panel"><h3>{$CA_IDENTITY.roster_code|escape:'html'} · {$CA_IDENTITY.real_name|escape:'html'} <span class="ca-admin__badge">{$CA_IDENTITY.state_label|default:'正常'|escape:'html'}</span></h3>
<p class="ca-admin__muted">席位模板 v{$CA_IDENTITY.seat_template_version|escape:'html'} · 建立于 {$CA_IDENTITY.created_at|escape:'html'}</p>
<table><thead><tr><th>席位</th><th>类型</th><th>状态</th><th>账号</th><th>姓名 / 关系</th><th>注册时间</th></tr></thead><tbody>
{foreach from=$CA_IDENTITY.seats item=seat}<tr><td>席位 {$seat.ordinal|escape:'html'}</td><td>{$seat.seat_type_label|escape:'html'}</td><td>{$seat.state_label|escape:'html'}</td><td>{if $seat.username}{$seat.username|escape:'html'}{else}—{/if}</td><td>{if $seat.account_real_name}{$seat.account_real_name|escape:'html'}{/if}{if $seat.family_relationship} · {$seat.family_relationship|escape:'html'}{/if}</td><td>{if $seat.bound_at}{$seat.bound_at|escape:'html'}{else}—{/if}</td></tr>{/foreach}
</tbody></table>
<div class="ca-admin__actions">
  <form method="post" class="ca-admin__inline" autocomplete="off" data-confirm="签发新认领码会立即废止旧认领码，确认继续？"><input type="hidden" name="pwg_token" value="{$CA_PWG_TOKEN|escape:'html'}"><input type="hidden" name="action" value="reissue_claim"><input type="hidden" name="identity_id" value="{$CA_IDENTITY.id|escape:'html'}"><label>签发 / 重发原因<input name="reason" maxlength="500" required></label><button type="submit">生成一次性认领码</button></form>
  {if $CA_IDENTITY.state == 'FROZEN'}
  <form method="post" class="ca-admin__inline"><input type="hidden" name="pwg_token" value="{$CA_PWG_TOKEN|escape:'html'}"><input type="hidden" name="action" value="unfreeze_identity"><input type="hidden" name="identity_id" value="{$CA_IDENTITY.id|escape:'html'}"><label>解除冻结原因<input name="reason" maxlength="500" required></label><button type="submit">解除冻结</button></form>
  {else}
  <form method="post" class="ca-admin__inline" data-confirm="冻结后该身份下账号将立即失去 Class Archive 授权，确认继续？"><input type="hidden" name="pwg_token" value="{$CA_PWG_TOKEN|escape:'html'}"><input type="hidden" name="action" value="freeze_identity"><input type="hidden" name="identity_id" value="{$CA_IDENTITY.id|escape:'html'}"><label>冻结原因<input name="reason" maxlength="500" required></label><button class="ca-admin__danger" type="submit">冻结身份</button></form>
  {/if}
</div></div>
{/if}
<div class="ca-admin__panel"><h3>同学身份</h3><table><thead><tr><th>同学编号</th><th>真实姓名</th><th>本人席位</th><th>家庭席位</th><th>匿名席位</th><th>身份状态</th><th>注册时间</th><th>最近活动</th><th>操作</th></tr></thead><tbody>
{foreach from=$CA_IDENTITIES item=identity}<tr><td>{$identity.roster_code|escape:'html'}</td><td>{$identity.real_name|escape:'html'}</td><td>{$identity.formal_seat_state_label|escape:'html'}</td><td>{$identity.family_used|escape:'html'} / {$identity.family_total|escape:'html'}</td><td>{if $identity.anonymous_state}{$identity.anonymous_state_label|escape:'html'}{else}未配置{/if}</td><td>{$identity.state_label|escape:'html'}</td><td>{if $identity.registered_at}{$identity.registered_at|escape:'html'}{else}未认领{/if}</td><td>{if $identity.last_activity}{$identity.last_activity|escape:'html'}{else}—{/if}</td><td><a href="{$CA_BASE_URL|escape:'html'}identities&amp;identity_id={$identity.id|escape:'url'}">查看</a></td></tr>{foreachelse}<tr><td colspan="9">尚未建立同学身份。</td></tr>{/foreach}
</tbody></table></div></section>

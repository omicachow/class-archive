{include file=$CA_PUBLIC_HEADER_TEMPLATE}
<header class="ca-public__head">
  <h2>我的身份</h2>
  <p class="ca-public__muted">查看当前账号状态，并管理属于你的可用席位。</p>
</header>
{if isset($CA_MY)}
  <div class="ca-public__panel">
    <div class="ca-public__summary">
      <div><span class="ca-public__label">身份</span><strong>{$CA_MY.identity_label|escape:'html'}</strong></div>
      <div><span class="ca-public__label">账号角色</span><strong>{$CA_MY.role_label|escape:'html'}</strong></div>
      <div><span class="ca-public__label">账号状态</span><strong>{$CA_MY.account_state_label|escape:'html'}</strong></div>
      {if isset($CA_MY.account_name) && $CA_MY.account_name}<div><span class="ca-public__label">登录账号</span><strong>{$CA_MY.account_name|escape:'html'}</strong></div>{/if}
      {if isset($CA_MY.relationship_label) && $CA_MY.relationship_label}<div><span class="ca-public__label">家庭关系</span><strong>{$CA_MY.relationship_label|escape:'html'}</strong></div>{/if}
    </div>
  </div>
  {if $CA_MY.role == 'CLASSMATE'}
    <div class="ca-public__panel">
      <h3>我的席位</h3>
      <ul class="ca-public__seats">
        {foreach from=$CA_MY.seats item=seat}
          <li class="ca-public__seat"><div><span class="ca-public__seat-main">席位 {$seat.ordinal|escape:'html'} · {$seat.type_label|escape:'html'}</span>{if $seat.account_name}<div>{$seat.account_name|escape:'html'}{if $seat.relationship_label} · {$seat.relationship_label|escape:'html'}{/if}</div>{/if}</div><div class="ca-public__seat-meta">{$seat.state_label|escape:'html'}{if $seat.account_state_label != '—'} · 账号 {$seat.account_state_label|escape:'html'}{/if}{if $seat.invite_expires_at}<br>邀请到期 {$seat.invite_expires_at|escape:'html'} UTC{/if}</div></li>
        {/foreach}
      </ul>
    </div>
    <div class="ca-public__panel">
      <h3>席位操作</h3>
      <div class="ca-public__actions">
        <form method="post" action="{$CA_MY_IDENTITY_URL|escape:'html'}"><input type="hidden" name="pwg_token" value="{$CA_PWG_TOKEN|escape:'html'}"><input type="hidden" name="action" value="issue_family_invitation"><button type="submit"{if !$CA_MY.can_issue_family} disabled{/if}>生成家庭邀请</button></form>
        <form method="post" action="{$CA_MY_IDENTITY_URL|escape:'html'}"><input type="hidden" name="pwg_token" value="{$CA_PWG_TOKEN|escape:'html'}"><input type="hidden" name="action" value="activate_anonymous"><button type="submit"{if !$CA_MY.can_activate_anonymous} disabled{/if}>激活匿名席位</button></form>
      </div>
      <p class="ca-public__muted">每次生成的邀请或匿名凭据只在当前无缓存响应中出现一次。</p>
    </div>
  {/if}
{/if}
<p><a class="ca-public__link" href="{$CA_GALLERY_URL|escape:'html'}">返回照片首页</a></p>
</section>

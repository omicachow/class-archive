{include file=$CA_PUBLIC_HEADER_TEMPLATE}
<header class="ca-public__head">
  <h2>认领班级身份</h2>
  <p class="ca-public__muted">此入口同时用于同学 Classmate ID 与老师 Teacher ID。Claim Code 仅可使用一次。</p>
</header>
{if isset($CA_SUCCESS) && $CA_SUCCESS}
  <div class="ca-public__panel">
    <h3>认领完成</h3>
    <p>系统没有保存或再次显示你的明文密码。</p>
    <div class="ca-public__actions"><a class="ca-public__button" href="{$CA_LOGIN_URL|escape:'html'}">前往登录</a><a class="ca-public__link" href="{$CA_GALLERY_URL|escape:'html'}">返回照片首页</a></div>
  </div>
{else}
  <form class="ca-public__panel ca-public__form" method="post" action="{$CA_CLAIM_URL|escape:'html'}" autocomplete="off">
    <input type="hidden" name="pwg_token" value="{$CA_PWG_TOKEN|escape:'html'}">
    <input type="hidden" name="action" value="claim">
    <label>Classmate ID / Teacher ID<input name="roster_code" type="text" maxlength="64" required autocapitalize="characters" autocomplete="off" placeholder="C25-018 或 T-001"></label>
    <label>一次性 Claim Code<input name="claim_code" type="password" maxlength="128" required autocomplete="one-time-code" spellcheck="false"></label>
    <label>登录用户名<input name="username" type="text" maxlength="64" required autocomplete="username" spellcheck="false"></label>
    <label>邮箱<input name="email" type="email" maxlength="255" required autocomplete="email"></label>
    <label>独立登录密码<input name="password" type="password" minlength="12" maxlength="1024" required autocomplete="new-password"></label>
    <label>再次输入密码<input name="password_confirmation" type="password" minlength="12" maxlength="1024" required autocomplete="new-password"></label>
    <button type="submit">认领并创建账号</button>
    <p class="ca-public__muted">若信息无效，页面不会说明是 ID、Code 还是账号字段错误。</p>
  </form>
{/if}
<p><a class="ca-public__link" href="{$CA_FAMILY_INVITE_URL|escape:'html'}">接受家庭席位邀请</a></p>
</section>

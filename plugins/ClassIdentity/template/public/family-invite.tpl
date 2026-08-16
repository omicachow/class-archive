{include file=$CA_PUBLIC_HEADER_TEMPLATE}
<header class="ca-public__head">
  <h2>接受家庭席位邀请</h2>
  <p class="ca-public__muted">家庭账号是独立账号。邀请一次有效、到期失效，并且只能访问 HERITAGE 内容。</p>
</header>
{if isset($CA_SUCCESS) && $CA_SUCCESS}
  <div class="ca-public__panel">
    <h3>注册完成</h3>
    <p>系统没有保存或再次显示你的明文密码。</p>
    <div class="ca-public__actions"><a class="ca-public__button" href="{$CA_LOGIN_URL|escape:'html'}">前往登录</a><a class="ca-public__link" href="{$CA_GALLERY_URL|escape:'html'}">返回照片首页</a></div>
  </div>
{else}
  <form class="ca-public__panel ca-public__form" method="post" action="{$CA_FAMILY_INVITE_URL|escape:'html'}" autocomplete="off">
    <input type="hidden" name="pwg_token" value="{$CA_PWG_TOKEN|escape:'html'}">
    <input type="hidden" name="action" value="accept_family">
    <label>一次性家庭邀请码<input name="invitation_code" type="password" maxlength="128" required autocomplete="one-time-code" spellcheck="false"></label>
    <label>真实姓名<input name="real_name" type="text" maxlength="190" required autocomplete="name"></label>
    <label>与同学的关系<select name="relationship" required><option value="">请选择</option><option value="FATHER">父亲</option><option value="MOTHER">母亲</option><option value="SIBLING">兄弟姐妹</option><option value="GUARDIAN">监护人</option><option value="OTHER_FAMILY">其他家属</option></select></label>
    <label>登录用户名<input name="username" type="text" maxlength="64" required autocomplete="username" spellcheck="false"></label>
    <label>邮箱<input name="email" type="email" maxlength="255" required autocomplete="email"></label>
    <label>独立登录密码<input name="password" type="password" minlength="12" maxlength="1024" required autocomplete="new-password"></label>
    <label>再次输入密码<input name="password_confirmation" type="password" minlength="12" maxlength="1024" required autocomplete="new-password"></label>
    <button type="submit">接受邀请并创建账号</button>
    <p class="ca-public__muted">邀请码与密码只通过安全表单提交，不应粘贴到浏览器地址栏。</p>
  </form>
{/if}
<p><a class="ca-public__link" href="{$CA_CLAIM_URL|escape:'html'}">返回身份认领</a></p>
</section>

{literal}
<style>
.ca-admin{--ca-border:#d9dee8;--ca-muted:#677287;--ca-danger:#b42318;--ca-ok:#067647;max-width:1440px;margin:0 auto;padding:4px 8px 32px;color:#202939}
.ca-admin *{box-sizing:border-box}.ca-admin__top{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:18px}.ca-admin h2{margin:0;font-size:25px}.ca-admin__subtitle{margin:5px 0 0;color:var(--ca-muted)}
.ca-admin__nav{display:flex;flex-wrap:wrap;gap:6px;padding:8px;border:1px solid var(--ca-border);border-radius:12px;background:#fff;margin-bottom:18px}.ca-admin__nav a{padding:8px 12px;border-radius:8px;text-decoration:none;font-weight:600}.ca-admin__nav a.is-active{background:#25324a;color:#fff}
.ca-admin__grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px;margin:12px 0 22px}.ca-admin__card,.ca-admin__panel{background:#fff;border:1px solid var(--ca-border);border-radius:12px;padding:16px}.ca-admin__metric{font-size:28px;font-weight:700;line-height:1.15}.ca-admin__label,.ca-admin__muted{color:var(--ca-muted)}
.ca-admin__panel{margin:14px 0;overflow:auto}.ca-admin__panel h3{margin:0 0 12px}.ca-admin table{width:100%;border-collapse:collapse;white-space:nowrap}.ca-admin th,.ca-admin td{text-align:left;padding:10px 9px;border-bottom:1px solid #edf0f5;vertical-align:top}.ca-admin th{font-size:12px;text-transform:uppercase;letter-spacing:.03em;color:var(--ca-muted)}
.ca-admin__form{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;align-items:end}.ca-admin label{display:grid;gap:5px;font-weight:600}.ca-admin input,.ca-admin select,.ca-admin textarea{width:100%;padding:9px;border:1px solid #bcc5d3;border-radius:7px;background:#fff}.ca-admin button,.ca-admin__button{display:inline-block;border:0;border-radius:7px;padding:9px 12px;background:#25324a;color:#fff;font-weight:700;text-decoration:none;cursor:pointer}.ca-admin button.ca-admin__danger{background:var(--ca-danger)}
.ca-admin__badge{display:inline-block;padding:3px 8px;border-radius:999px;background:#eef2f7;font-size:12px;font-weight:700}.ca-admin__badge--danger{color:#fff;background:var(--ca-danger)}.ca-admin__badge--ok{color:#fff;background:var(--ca-ok)}
.ca-admin__alert{padding:12px 14px;border-radius:8px;margin:10px 0;background:#fff3e0;border:1px solid #f2c27b}.ca-admin__alert--success{background:#ecfdf3;border-color:#abefc6}.ca-admin__alert--error{background:#fef3f2;border-color:#fecdca}.ca-admin__blocked{padding:16px;border:2px solid var(--ca-danger);background:#fff1f0;color:var(--ca-danger);font-weight:800;border-radius:10px;margin-bottom:16px}
.ca-admin__actions{display:flex;flex-wrap:wrap;gap:8px}.ca-admin__inline{display:flex;gap:8px;align-items:end;flex-wrap:wrap}.ca-admin__inline input{min-width:220px}.ca-admin__code{display:block;padding:16px;background:#111827;color:#fff;border-radius:8px;font:700 17px/1.5 ui-monospace,SFMono-Regular,Consolas,monospace;overflow-wrap:anywhere;user-select:all}.ca-admin details{margin-top:10px}
@media(max-width:700px){.ca-admin__top{display:block}.ca-admin table{white-space:normal}.ca-admin__panel{padding:12px}.ca-admin__nav a{flex:1 0 auto;text-align:center}}
</style>
<script>document.addEventListener('submit',function(e){var f=e.target;if(f&&f.dataset&&f.dataset.confirm&&!window.confirm(f.dataset.confirm)){e.preventDefault();}},false);</script>
{/literal}
<section class="ca-admin">
  <header class="ca-admin__top">
    <div><h2>Class Archive 管理控制台</h2><p class="ca-admin__subtitle">身份、席位、注册与安全健康的业务控制面</p></div>
    <a class="ca-admin__button" href="{$CA_NATIVE_ADMIN_URL|escape:'html'}">Piwigo 技术后台</a>
  </header>
  <nav class="ca-admin__nav" aria-label="Class Archive 管理导航">
    {foreach from=$CA_NAV item=nav}
      <a href="{$CA_BASE_URL|escape:'html'}{$nav.id|escape:'url'}"{if $CA_TAB == $nav.id} class="is-active" aria-current="page"{/if}>{$nav.label|escape:'html'}</a>
    {/foreach}
  </nav>
  {if isset($CA_READ_ERROR)}<div class="ca-admin__alert ca-admin__alert--error">{$CA_READ_ERROR|escape:'html'}</div>{/if}
  {if isset($CA_FLASH)}{foreach from=$CA_FLASH item=notice}<div class="ca-admin__alert ca-admin__alert--{$notice.kind|escape:'html'}">{$notice.message|escape:'html'}</div>{/foreach}{/if}

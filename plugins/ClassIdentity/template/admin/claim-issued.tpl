{include file=$CA_HEADER_TEMPLATE}
<div class="ca-admin__alert ca-admin__alert--success"><strong>认领码已生成。</strong>旧认领码已永久失效。</div>
<div class="ca-admin__panel"><h3>一次性认领码</h3><p>请现在复制并通过安全渠道交给对应成员。离开本页后，系统无法再次显示此认领码，只能重新签发。</p>
<code class="ca-admin__code">{$CA_ISSUED.code|escape:'html'}</code>
<p class="ca-admin__muted">席位 #{$CA_ISSUED.seat_id|escape:'html'} · 第 {$CA_ISSUED.generation|escape:'html'} 代 · 到期 {$CA_ISSUED.expires_at|escape:'html'} UTC</p>
<a class="ca-admin__button" href="{$CA_BASE_URL|escape:'html'}{$CA_TAB|escape:'url'}">我已安全保存，返回</a></div></section>

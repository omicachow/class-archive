{include file=$CA_HEADER_TEMPLATE}
<div class="ca-admin__panel"><h3>审计记录</h3><p class="ca-admin__muted">只显示脱敏事件；密码、原始认领码、邀请码、会话密钥与 API 密钥不会被记录。</p>
<table><thead><tr><th>时间</th><th>管理员</th><th>操作</th><th>对象</th><th>原因</th><th>结果</th><th>请求编号</th></tr></thead><tbody>
{foreach from=$CA_AUDIT item=row}<tr><td>{$row.occurred_at|escape:'html'}</td><td>{$row.actor_name|escape:'html'} (#{$row.actor_user_id|escape:'html'})</td><td>{$row.action_label|escape:'html'}</td><td>{$row.target_type_label|escape:'html'} {$row.target_id|escape:'html'}</td><td>{if $row.reason}{$row.reason|escape:'html'}{else}—{/if}</td><td>{$row.result_label|escape:'html'}{if $row.error_code} · 需要查看服务日志{/if}</td><td><code>{$row.request_id|escape:'html'}</code></td></tr>{foreachelse}<tr><td colspan="7">暂无审计记录。</td></tr>{/foreach}
</tbody></table></div></section>

{include file=$CA_HEADER_TEMPLATE}
<div class="ca-admin__panel"><h3>匿名管理</h3><p class="ca-admin__muted">普通成员只能看到 context-scoped 匿名名。只有在本页明确点击“查看真实身份”并填写理由后，系统才会解析并记录审计。</p>
<table><thead><tr><th>对外匿名名</th><th>上下文</th><th>身份映射</th><th>状态</th><th>互动摘要</th><th>操作</th></tr></thead><tbody>
{foreach from=$CA_ANONYMOUS item=row}
<tr><td>{$row.alias|escape:'html'}</td><td>{$row.context_label|escape:'html'}</td><td>需点击“查看真实身份”后才可查看<br>每次查看都会写入操作审计</td><td>{$row.seat_state_label|escape:'html'} / {$row.account_state_label|escape:'html'}</td><td>{$row.comment_count|escape:'html'} 条评论{if $row.last_comment_at}<br>{$row.last_comment_at|escape:'html'}{/if}<br>举报：当前未接入</td><td>
{if $row.seat_state == 'ACTIVE'}<form method="post" class="ca-admin__inline" data-confirm="确认禁用该匿名席位并撤销会话？"><input type="hidden" name="pwg_token" value="{$CA_PWG_TOKEN|escape:'html'}"><input type="hidden" name="action" value="disable_anonymous"><input type="hidden" name="seat_id" value="{$row.seat_id|escape:'html'}"><input required name="reason" maxlength="500" placeholder="操作理由"><button class="ca-admin__danger">禁用</button></form>{else}<form method="post" class="ca-admin__inline"><input type="hidden" name="pwg_token" value="{$CA_PWG_TOKEN|escape:'html'}"><input type="hidden" name="action" value="enable_anonymous"><input type="hidden" name="seat_id" value="{$row.seat_id|escape:'html'}"><input required name="reason" maxlength="500" placeholder="恢复理由"><button>恢复</button></form>{/if}
{if $row.comment_count > 0}<form method="post" class="ca-admin__inline"><input type="hidden" name="pwg_token" value="{$CA_PWG_TOKEN|escape:'html'}"><input type="hidden" name="action" value="resolve_anonymous"><input type="hidden" name="context_type" value="PHOTO"><input type="hidden" name="context_id" value="{$row.context_image_id|default:0|escape:'html'}"><input type="hidden" name="alias" value="{$row.alias|escape:'html'}"><input required name="reason" maxlength="500" placeholder="查看理由"><button>查看真实身份</button></form>{else}<span class="ca-admin__muted">暂无可解析上下文</span>{/if}
</td></tr>
{foreachelse}<tr><td colspan="6">暂无匿名席位</td></tr>{/foreach}
</tbody></table></div>
</section>

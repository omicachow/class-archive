{include file=$CA_HEADER_TEMPLATE}
<div class="ca-admin__panel"><h3>家庭投稿审核</h3><p class="ca-admin__muted">家庭席位只能提交班级历史照片。待审核原图仅在此处、通过 MediaGuard 后提供给系统管理员。</p>
<table><thead><tr><th>预览</th><th>投稿人与关系</th><th>文件信息</th><th>建议档案信息</th><th>状态</th><th>审核</th></tr></thead><tbody>
{foreach from=$CA_SUBMISSIONS item=submission}
<tr>
  <td>{if $submission.state == 'PENDING'}<img src="{$CA_BASE_URL|escape:'html'}submissions&amp;action=submission_thumbnail&amp;submission_id={$submission.id|escape:'url'}" alt="投稿缩略图" width="120" loading="lazy">{else}—{/if}</td>
  <td>{$submission.roster_code|escape:'html'} · {$submission.real_name|escape:'html'}<br>{$submission.relationship_label|escape:'html'}<br><small>{$submission.username|escape:'html'}</small></td>
  <td>{$submission.original_filename|escape:'html'}<br>{$submission.mime_type|escape:'html'} · {$submission.byte_size|escape:'html'} bytes<br>{$submission.width|escape:'html'} × {$submission.height|escape:'html'}<br>{$submission.uploaded_at|escape:'html'}</td>
  <td>{if $submission.suggested_date}{$submission.suggested_date|escape:'html'} · {/if}{$submission.precision_label|escape:'html'}<br>{$submission.suggested_album|default:'未指定相册'|escape:'html'}<br>{$submission.description|default:'—'|escape:'html'}</td>
  <td><span class="ca-admin__badge">{$submission.state_label|escape:'html'}</span>{if $submission.review_reason}<br><small>{$submission.review_reason|escape:'html'}</small>{/if}</td>
  <td>{if $submission.state == 'PENDING'}
    <form method="post" class="ca-admin__inline" data-confirm="确认通过并收录到班级历史？"><input type="hidden" name="pwg_token" value="{$CA_PWG_TOKEN|escape:'html'}"><input type="hidden" name="action" value="approve_submission"><input type="hidden" name="submission_id" value="{$submission.id|escape:'html'}"><select name="album_id"><option value="">班级历史根相册</option>{foreach from=$CA_ARCHIVE_ALBUMS item=album}<option value="{$album.id|escape:'html'}">{$album.name|escape:'html'}</option>{/foreach}</select><select name="date_precision"><option value="UNKNOWN">日期未知</option><option value="EXACT">日期精确</option><option value="MONTH">仅确定到月份</option><option value="YEAR">仅确定年份</option><option value="TERM">仅确定学期</option></select><input type="date" name="archive_date" aria-label="档案日期"><input type="text" name="event_label" maxlength="190" placeholder="事件标签"><input required name="reason" maxlength="500" placeholder="审核理由"><button type="submit">通过</button></form>
    <form method="post" class="ca-admin__inline" data-confirm="确认拒绝这份投稿？"><input type="hidden" name="pwg_token" value="{$CA_PWG_TOKEN|escape:'html'}"><input type="hidden" name="action" value="reject_submission"><input type="hidden" name="submission_id" value="{$submission.id|escape:'html'}"><input required name="reason" maxlength="500" placeholder="拒绝理由"><button class="ca-admin__danger" type="submit">拒绝</button></form>
  {else}审核完成{/if}</td>
</tr>
{foreachelse}<tr><td colspan="6">暂无投稿</td></tr>{/foreach}
</tbody></table></div>
</section>

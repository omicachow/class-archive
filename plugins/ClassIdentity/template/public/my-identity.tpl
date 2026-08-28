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
  {if $CA_MY.role == 'FAMILY'}
    <div class="ca-public__panel">
      <h3>提交班级历史照片</h3>
      <p class="ca-public__muted">家庭席位只能提交“班级历史”照片。照片会先进入待审核区，管理员通过后才会进入正式档案；提交中的原图不会对家庭账号开放。</p>
      <form method="post" enctype="multipart/form-data" action="{$CA_MY_IDENTITY_URL|escape:'html'}">
        <input type="hidden" name="pwg_token" value="{$CA_PWG_TOKEN|escape:'html'}">
        <input type="hidden" name="action" value="submit_family_photo">
        <input type="hidden" name="era" value="HERITAGE">
        <label>照片文件（JPEG / PNG / WebP，最大 20 MiB）<input type="file" name="submission_file" accept="image/jpeg,image/png,image/webp" required></label>
        <label>建议档案日期（可留空）<input type="date" name="suggested_date"></label>
        <label>日期可信范围
          <select name="date_precision">
            <option value="UNKNOWN">日期未知</option><option value="EXACT">日期精确</option><option value="DAY">仅确定到日</option><option value="MONTH">仅确定到月份</option><option value="YEAR">仅确定年份</option><option value="TERM">仅确定学期</option><option value="EVENT_ONLY">仅确定事件</option>
          </select>
        </label>
        <label>建议归档相册（可留空）<input type="text" name="suggested_album" maxlength="190" placeholder="例如：初三春游"></label>
        <label>说明（可留空）<textarea name="description" maxlength="2000" rows="3" placeholder="你记得的时间、地点或人物"></textarea></label>
        <button type="submit">提交审核</button>
      </form>
    </div>
    <div class="ca-public__panel">
      <h3>我的投稿</h3>
      <table><thead><tr><th>状态</th><th>文件</th><th>上传时间</th><th>建议日期</th><th>说明</th><th>审核意见</th></tr></thead><tbody>
      {foreach from=$CA_MY.submissions item=submission}
        <tr><td>{$submission.state_label|escape:'html'}</td><td>{$submission.original_filename|escape:'html'}<br><small>{$submission.width|escape:'html'} × {$submission.height|escape:'html'} · {$submission.byte_size|escape:'html'} bytes</small></td><td>{$submission.uploaded_at|escape:'html'}</td><td>{if $submission.suggested_date}{$submission.suggested_date|escape:'html'} · {$submission.precision_label|escape:'html'}{else}{$submission.precision_label|escape:'html'}{/if}</td><td>{$submission.description|default:'—'|escape:'html'}</td><td>{$submission.review_reason|default:'—'|escape:'html'}</td></tr>
      {foreachelse}<tr><td colspan="6">暂无投稿记录</td></tr>{/foreach}
      </tbody></table>
    </div>
  {/if}
{/if}
<p><a class="ca-public__link" href="{$CA_GALLERY_URL|escape:'html'}">返回照片首页</a></p>
</section>

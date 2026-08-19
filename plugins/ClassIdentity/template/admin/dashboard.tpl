{include file=$CA_HEADER_TEMPLATE}
{if isset($CA_DASHBOARD)}
  {if $CA_DASHBOARD.production_blocked}<div class="ca-admin__blocked">尚未达到生产环境放行条件——安全或迁移门禁未通过。当前仍禁止导入真实照片、连接 NAS 或开放公网。</div>{/if}
  {if $CA_DASHBOARD.production_blocked}<details><summary>技术门禁详情</summary><code>PRODUCTION BLOCKED</code></details>{/if}
  <div class="ca-admin__grid">
    <div class="ca-admin__card"><div class="ca-admin__metric">{$CA_DASHBOARD.classmate_claimed|escape:'html'} / {$CA_DASHBOARD.classmate_total|escape:'html'}</div><div class="ca-admin__label">已认领 / 同学身份</div></div>
    <div class="ca-admin__card"><div class="ca-admin__metric">{$CA_DASHBOARD.classmate_unclaimed|escape:'html'}</div><div class="ca-admin__label">未认领同学</div></div>
    <div class="ca-admin__card"><div class="ca-admin__metric">{$CA_DASHBOARD.teacher_claimed|escape:'html'} / {$CA_DASHBOARD.teacher_total|escape:'html'}</div><div class="ca-admin__label">已认领 / 老师身份</div></div>
    <div class="ca-admin__card"><div class="ca-admin__metric">{$CA_DASHBOARD.family_used|escape:'html'} / {$CA_DASHBOARD.family_available|escape:'html'}</div><div class="ca-admin__label">家庭席位已用 / 可用</div></div>
    <div class="ca-admin__card"><div class="ca-admin__metric">{$CA_DASHBOARD.anonymous_active|escape:'html'}</div><div class="ca-admin__label">已激活匿名席位</div></div>
    <div class="ca-admin__card"><div class="ca-admin__metric">{$CA_DASHBOARD.frozen_accounts|escape:'html'}</div><div class="ca-admin__label">冻结账号 / 席位</div></div>
  </div>
  <h3>内容摘要</h3>
  <div class="ca-admin__grid">
    <div class="ca-admin__card"><div class="ca-admin__metric">{$CA_DASHBOARD.heritage_images|escape:'html'}</div><div class="ca-admin__label">班级历史图片</div></div>
    <div class="ca-admin__card"><div class="ca-admin__metric">{$CA_DASHBOARD.living_images|escape:'html'}</div><div class="ca-admin__label">毕业后动态图片</div></div>
    <div class="ca-admin__card"><div class="ca-admin__metric">{$CA_DASHBOARD.pending_submissions|escape:'html'}</div><div class="ca-admin__label">待审核投稿</div></div>
    <div class="ca-admin__card"><div class="ca-admin__metric">{$CA_DASHBOARD.recent_uploads|escape:'html'}</div><div class="ca-admin__label">最近 30 天上传</div></div>
  </div>
  <h3>系统门禁</h3>
  <div class="ca-admin__grid">
    <div class="ca-admin__card"><div class="ca-admin__metric">{$CA_DASHBOARD.media_guard_label|escape:'html'}</div><div class="ca-admin__label">媒体访问防护</div></div>
    <div class="ca-admin__card"><div class="ca-admin__metric">{$CA_DASHBOARD.media_attestation_label|escape:'html'}</div><div class="ca-admin__label">媒体访问安全验证</div></div>
    <div class="ca-admin__card"><div class="ca-admin__metric">{$CA_DASHBOARD.reconciliation_label|escape:'html'}</div><div class="ca-admin__label">数据一致性</div></div>
    <div class="ca-admin__card"><div class="ca-admin__metric">{$CA_DASHBOARD.database_label|escape:'html'}</div><div class="ca-admin__label">数据库</div></div>
    <div class="ca-admin__card"><div class="ca-admin__metric">{$CA_DASHBOARD.migration_label|escape:'html'}</div><div class="ca-admin__label">身份系统迁移</div></div>
    <div class="ca-admin__card"><div class="ca-admin__metric">{$CA_DASHBOARD.failed_manual_operations|escape:'html'} / {$CA_DASHBOARD.compensation_required_accounts|escape:'html'}</div><div class="ca-admin__label">人工处理失败 / 待补偿账号</div></div>
    <div class="ca-admin__card"><div class="ca-admin__metric">{$CA_DASHBOARD.stale_provisioning_operations|escape:'html'} / {$CA_DASHBOARD.stale_provisioning_accounts|escape:'html'} / {$CA_DASHBOARD.stale_provisioning_seats|escape:'html'}</div><div class="ca-admin__label">长期账号 provisioning：操作 / 账号 / 席位</div></div>
  </div>
{/if}
{if isset($CA_RECENT_AUDIT)}
<div class="ca-admin__panel"><h3>最近管理员操作</h3><table><thead><tr><th>时间</th><th>管理员</th><th>操作</th><th>对象</th><th>结果</th></tr></thead><tbody>
{foreach from=$CA_RECENT_AUDIT item=row}<tr><td>{$row.occurred_at|escape:'html'}</td><td>{$row.actor_name|escape:'html'}</td><td>{$row.action_label|escape:'html'}</td><td>{$row.target_type_label|escape:'html'} {$row.target_id|escape:'html'}</td><td>{$row.result_label|escape:'html'}</td></tr>{foreachelse}<tr><td colspan="5">暂无审计记录</td></tr>{/foreach}
</tbody></table></div>
{/if}
</section>

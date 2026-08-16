{include file=$CA_HEADER_TEMPLATE}
{if isset($CA_SYSTEM)}
{if $CA_SYSTEM.production_blocked}<div class="ca-admin__blocked">PRODUCTION BLOCKED — 至少一项生产安全门禁失败。</div>{else}<div class="ca-admin__alert ca-admin__alert--success">当前可检测的安全底座与 migration 已通过。</div>{/if}
<div class="ca-admin__panel"><h3>系统健康</h3><table><tbody>
<tr><th>MediaGuard 配置</th><td>{if $CA_SYSTEM.media_guard == 'CONFIGURED'}<span class="ca-admin__badge ca-admin__badge--ok">CONFIGURED</span>{else}<span class="ca-admin__badge ca-admin__badge--danger">FAIL</span>{/if}</td></tr>
<tr><th>MediaGuard HTTP attestation</th><td>{$CA_SYSTEM.media_guard_http_attestation|escape:'html'}</td></tr>
<tr><th>匿名呈现边界</th><td>{$CA_SYSTEM.anonymous_presenter|escape:'html'}</td></tr>
<tr><th>Identity Enforcement</th><td>{$CA_SYSTEM.identity_enforcement|escape:'html'}</td></tr><tr><th>独立 SYSTEM_ADMIN</th><td>{$CA_SYSTEM.system_admins|escape:'html'}</td></tr><tr><th>业务角色映射</th><td>{$CA_SYSTEM.role_group_mappings|escape:'html'}</td></tr><tr><th>Secret 配置</th><td>{$CA_SYSTEM.secret_configuration|escape:'html'}</td></tr>
<tr><th>Database</th><td>{$CA_SYSTEM.database|escape:'html'}</td></tr><tr><th>Migration</th><td>{$CA_SYSTEM.migration|escape:'html'}</td></tr><tr><th>Schema attestation</th><td>{$CA_SYSTEM.schema_verification|escape:'html'}</td></tr><tr><th>缺失表</th><td>{if $CA_SYSTEM.missing_tables}{$CA_SYSTEM.missing_tables|escape:'html'}{else}无{/if}</td></tr>
<tr><th>Storage</th><td>可用 {$CA_SYSTEM.storage_free|escape:'html'} / 总计 {$CA_SYSTEM.storage_total|escape:'html'}</td></tr><tr><th>Derivative Cache</th><td>{$CA_SYSTEM.derivative_cache|escape:'html'}</td></tr>
<tr><th>最近成功备份</th><td>{$CA_SYSTEM.backup_last_success|escape:'html'}</td></tr><tr><th>最近失败备份</th><td>{$CA_SYSTEM.backup_last_failure|escape:'html'}</td></tr><tr><th>Cron / Job</th><td>{$CA_SYSTEM.cron_jobs|escape:'html'}</td></tr>
<tr><th>Admin MFA</th><td>{$CA_SYSTEM.admin_mfa|escape:'html'}</td></tr><tr><th>Production blockers</th><td>{$CA_SYSTEM.production_blockers|escape:'html'}</td></tr>
<tr><th>FAILED_MANUAL operations</th><td>{$CA_SYSTEM.failed_manual_operations|escape:'html'}</td></tr>
<tr><th>COMPENSATION_REQUIRED accounts</th><td>{$CA_SYSTEM.compensation_required_accounts|escape:'html'}</td></tr>
<tr><th>Provisioning health</th><td>{$CA_SYSTEM.provisioning_health|escape:'html'}</td></tr>
<tr><th>长期 Provisioning（操作 / 账号 / 席位）</th><td>{$CA_SYSTEM.stale_provisioning_operations|escape:'html'} / {$CA_SYSTEM.stale_provisioning_accounts|escape:'html'} / {$CA_SYSTEM.stale_provisioning_seats|escape:'html'}</td></tr>
<tr><th>ClassIdentity</th><td>{$CA_SYSTEM.plugin_version|escape:'html'}</td></tr><tr><th>Piwigo Core</th><td>{$CA_SYSTEM.core_version|escape:'html'}</td></tr>
</tbody></table><p class="ca-admin__muted">插件、主题和 Piwigo Core 技术配置继续由原生后台负责；此页只呈现业务运行门禁。</p></div>
{/if}
{if isset($CA_PROVISIONING_INCIDENTS)}
<div class="ca-admin__panel"><h3>Provisioning 故障</h3><p class="ca-admin__muted">只有已证明 Core 用户由本次 saga 创建、尚无 Principal 的故障可安全补偿。来源不确定的失败保持阻断，不能在后台猜测修复。</p>
<table><thead><tr><th>Operation</th><th>Identity / Seat</th><th>状态</th><th>错误码</th><th>更新时间</th><th>处理</th></tr></thead><tbody>
{foreach from=$CA_PROVISIONING_INCIDENTS item=incident}<tr><td>#{$incident.id|escape:'html'} · {$incident.operation_type|escape:'html'}</td><td>{$incident.roster_code|escape:'html'} · {$incident.real_name|escape:'html'} · {$incident.seat_type|escape:'html'}</td><td>{$incident.operation_state|escape:'html'} / {$incident.account_state|escape:'html'} / {$incident.seat_state|escape:'html'}</td><td>{$incident.last_error_code|escape:'html'}</td><td>{$incident.updated_at|escape:'html'}</td><td>{if $incident.repairable}<form method="post" data-confirm="此操作将撤销故障 Core 账号的全部凭据和组关系，保留不可登录 tombstone，并释放席位。确认继续？"><input type="hidden" name="pwg_token" value="{$CA_PWG_TOKEN|escape:'html'}"><input type="hidden" name="action" value="compensate_provisioning"><input type="hidden" name="operation_id" value="{$incident.id|escape:'html'}"><label>补偿原因<input name="reason" maxlength="500" required></label><button class="ca-admin__danger" type="submit">安全补偿</button></form>{else}<span class="ca-admin__badge ca-admin__badge--danger">需人工核查，继续阻断</span>{/if}</td></tr>{foreachelse}<tr><td colspan="6">无 provisioning 故障。</td></tr>{/foreach}
</tbody></table></div>
{/if}
</section>

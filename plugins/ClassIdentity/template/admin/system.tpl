{include file=$CA_HEADER_TEMPLATE}
{if isset($CA_SYSTEM)}
{if $CA_SYSTEM.production_blocked}<div class="ca-admin__blocked">尚未达到生产环境放行条件——至少一项生产安全门禁失败。</div>{else}<div class="ca-admin__alert ca-admin__alert--success">当前可检测的安全底座与迁移检查已通过。</div>{/if}
{if $CA_SYSTEM.production_blocked}<details><summary>技术门禁详情</summary><code>PRODUCTION BLOCKED · {$CA_SYSTEM.production_blockers|escape:'html'}</code></details>{/if}
<div class="ca-admin__panel"><h3>系统状态</h3><table><tbody>
<tr><th>媒体访问防护</th><td>{if $CA_SYSTEM.media_guard == 'CONFIGURED'}<span class="ca-admin__badge ca-admin__badge--ok">已配置</span>{else}<span class="ca-admin__badge ca-admin__badge--danger">未通过</span>{/if}</td></tr>
<tr><th>媒体访问安全验证</th><td>{if $CA_SYSTEM.media_guard_http_attestation == 'VERIFIED'}<span class="ca-admin__badge ca-admin__badge--ok">{$CA_SYSTEM.media_attestation_label|escape:'html'}</span>{else}<span class="ca-admin__badge ca-admin__badge--danger">{$CA_SYSTEM.media_attestation_label|escape:'html'}</span>{/if}<br><span class="ca-admin__muted">{$CA_SYSTEM.media_attestation_message|escape:'html'}{if $CA_SYSTEM.media_attestation_timestamp} 验证时间：{$CA_SYSTEM.media_attestation_timestamp|escape:'html'}；测试数量：{$CA_SYSTEM.media_attestation_probes|escape:'html'}。{/if}</span></td></tr>
<tr><th>数据一致性</th><td>{if $CA_SYSTEM.reconciliation == 'CLEAR'}<span class="ca-admin__badge ca-admin__badge--ok">{$CA_SYSTEM.reconciliation_label|escape:'html'}</span>{else}<span class="ca-admin__badge ca-admin__badge--danger">{$CA_SYSTEM.reconciliation_label|escape:'html'}</span>{/if}<br><span class="ca-admin__muted">{$CA_SYSTEM.reconciliation_message|escape:'html'}{if $CA_SYSTEM.reconciliation_timestamp} 检查时间：{$CA_SYSTEM.reconciliation_timestamp|escape:'html'}。{/if}</span></td></tr>
<tr><th>匿名脱敏呈现</th><td>{if $CA_SYSTEM.anonymous_presenter == 'READY'}已就绪{else}未通过{/if}</td></tr>
<tr><th>身份权限强制</th><td>{if $CA_SYSTEM.identity_enforcement == 'ENFORCED'}已启用{else}已停用{/if}</td></tr><tr><th>独立系统管理员</th><td>{$CA_SYSTEM.system_admins|escape:'html'} 个</td></tr><tr><th>业务角色映射</th><td>{$CA_SYSTEM.role_group_mappings|escape:'html'}</td></tr><tr><th>密钥配置</th><td>{if $CA_SYSTEM.secret_configuration == 'Configured'}已配置{else}异常{/if}</td></tr>
<tr><th>数据库</th><td>{if $CA_SYSTEM.database == 'Healthy'}正常{else}异常{/if}</td></tr><tr><th>迁移</th><td>{$CA_SYSTEM.migration_label|escape:'html'}</td></tr><tr><th>数据结构校验</th><td>{if $CA_SYSTEM.schema_verification == 'PASS'}通过{else}未通过{/if}</td></tr><tr><th>缺失表</th><td>{if $CA_SYSTEM.missing_tables}{$CA_SYSTEM.missing_tables|escape:'html'}{else}无{/if}</td></tr>
<tr><th>存储空间</th><td>可用 {$CA_SYSTEM.storage_free|escape:'html'} / 总计 {$CA_SYSTEM.storage_total|escape:'html'}</td></tr><tr><th>衍生图缓存</th><td>{$CA_SYSTEM.derivative_cache|escape:'html'}</td></tr>
<tr><th>最近成功备份</th><td>{$CA_SYSTEM.backup_last_success|escape:'html'}</td></tr><tr><th>最近失败备份</th><td>{$CA_SYSTEM.backup_last_failure|escape:'html'}</td></tr><tr><th>后台维护</th><td>{$CA_SYSTEM.cron_jobs|escape:'html'}{if $CA_SYSTEM.maintenance_timestamp}<br><span class="ca-admin__muted">{$CA_SYSTEM.maintenance_message|escape:'html'} 执行时间：{$CA_SYSTEM.maintenance_timestamp|escape:'html'}。</span>{/if}</td></tr>
<tr><th>管理员多因素认证</th><td>尚未配置</td></tr><tr><th>生产阻断项</th><td>{$CA_SYSTEM.production_blockers_label|escape:'html'}</td></tr>
<tr><th>人工处理失败</th><td>{$CA_SYSTEM.failed_manual_operations|escape:'html'}</td></tr>
<tr><th>待补偿账号</th><td>{$CA_SYSTEM.compensation_required_accounts|escape:'html'}</td></tr>
<tr><th>账号开通健康度</th><td>{if $CA_SYSTEM.provisioning_health == 'CLEAR'}正常{elseif $CA_SYSTEM.provisioning_health == 'BLOCKED'}阻断{else}异常{/if}</td></tr>
<tr><th>长期开通状态（操作 / 账号 / 席位）</th><td>{$CA_SYSTEM.stale_provisioning_operations|escape:'html'} / {$CA_SYSTEM.stale_provisioning_accounts|escape:'html'} / {$CA_SYSTEM.stale_provisioning_seats|escape:'html'}</td></tr>
<tr><th>身份插件版本（ClassIdentity）</th><td>{$CA_SYSTEM.plugin_version|escape:'html'}</td></tr><tr><th>Piwigo 核心版本</th><td>{$CA_SYSTEM.core_version|escape:'html'}</td></tr>
</tbody></table><p class="ca-admin__muted">插件、主题和 Piwigo Core 技术配置继续由原生后台负责；此页只呈现业务运行门禁。</p></div>
{/if}
{if isset($CA_PROVISIONING_INCIDENTS)}
<div class="ca-admin__panel"><h3>Provisioning 故障</h3><p class="ca-admin__muted">只有已证明 Core 用户由本次 saga 创建、尚无 Principal 的故障可安全补偿。来源不确定的失败保持阻断，不能在后台猜测修复。</p>
<table><thead><tr><th>操作</th><th>身份 / 席位</th><th>状态</th><th>问题</th><th>更新时间</th><th>处理</th></tr></thead><tbody>
{foreach from=$CA_PROVISIONING_INCIDENTS item=incident}<tr><td>#{$incident.id|escape:'html'} · {$incident.operation_type_label|escape:'html'}</td><td>{$incident.roster_code|escape:'html'} · {$incident.real_name|escape:'html'} · {$incident.seat_type_label|escape:'html'}</td><td>{$incident.operation_state_label|escape:'html'} / {$incident.account_state_label|escape:'html'} / {$incident.seat_state_label|escape:'html'}</td><td>{$incident.error_label|escape:'html'}</td><td>{$incident.updated_at|escape:'html'}</td><td>{if $incident.repairable}<form method="post" data-confirm="此操作将撤销故障 Core 账号的全部凭据和组关系，保留不可登录 tombstone，并释放席位。确认继续？"><input type="hidden" name="pwg_token" value="{$CA_PWG_TOKEN|escape:'html'}"><input type="hidden" name="action" value="compensate_provisioning"><input type="hidden" name="operation_id" value="{$incident.id|escape:'html'}"><label>补偿原因<input name="reason" maxlength="500" required></label><button class="ca-admin__danger" type="submit">安全补偿</button></form>{else}<span class="ca-admin__badge ca-admin__badge--danger">需人工核查，继续阻断</span>{/if}</td></tr>{foreachelse}<tr><td colspan="6">无账号开通故障。</td></tr>{/foreach}
</tbody></table></div>
{/if}
</section>

<?php
// includes/activity_panel.php
// Renders a compact Order Activity panel. Expects $conn to be available.

if (!isset($conn)) {
    echo '<div class="card"><h2>Order Activity Log</h2><p style="color:#c00;">Database connection ($conn) not found.</p></div>';
    return;
}

require_once __DIR__ . '/activity_logger.php';
$activities = getRecentOrderActivities($conn, 20);
?>
<div class="card" style="margin-top:18px;">
  <h2>Order Activity Log</h2>
  <?php if (empty($activities)): ?>
    <p class="empty-message">No recent activity.</p>
  <?php else: ?>
    <div style="max-height:340px; overflow:auto; padding-right:8px;">
      <table style="width:100%; border-collapse:collapse; font-size:0.95rem;">
        <thead>
          <tr style="text-align:left; color:#666;">
            <th style="padding:8px 6px; width:18%;">When</th>
            <th style="padding:8px 6px; width:18%;">Staff</th>
            <th style="padding:8px 6px; width:12%;">Role</th>
            <th style="padding:8px 6px;">Action</th>
            <th style="padding:8px 6px; width:12%;">Order</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($activities as $act): ?>
            <tr style="border-top:1px solid #f0f0f0;">
              <td style="padding:8px 6px; vertical-align:top;">
                <?= htmlspecialchars(date('M d, Y H:i', strtotime($act['created_at']))) ?>
              </td>
              <td style="padding:8px 6px; vertical-align:top;">
                <?= htmlspecialchars($act['staff_username'] ?: ($act['staff_id'] ?: 'System')) ?>
              </td>
              <td style="padding:8px 6px; vertical-align:top;">
                <?= htmlspecialchars($act['role'] ?: '—') ?>
              </td>
              <td style="padding:8px 6px; vertical-align:top; text-transform:capitalize;">
                <?= htmlspecialchars($act['action'] ?: '—') ?>
              </td>
              <td style="padding:8px 6px; vertical-align:top;">
                <?= $act['order_id'] ? 'ID:' . htmlspecialchars($act['order_id']) : '—' ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
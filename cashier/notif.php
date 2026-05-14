<?php
session_start();
include "../config.php";

// Optional: Only allow logged in staff (optional, add your auth check if needed)
// if (!isset($_SESSION['staff_id'])) { header('Location: login.php'); exit; }

$sql = "SELECT * FROM order_requests ORDER BY created_at ASC";
$result = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Customer Requests</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
body { font-family: Arial, sans-serif; padding: 20px; }
h2 { margin-top: 0; }
table { width: 100%; border-collapse: collapse; margin-top: 20px; }
th, td { border: 1px solid #ddd; padding: 10px; text-align: center; }
th { background: #ff5722; color: white; }
.status-pending { color: #ff9800; font-weight: bold; }
.status-ack { color: #2196f3; font-weight: bold; }
.status-done { color: #4caf50; font-weight: bold; }
button { padding: 6px 12px; border: none; border-radius: 5px; cursor: pointer; }
.btn-ack { background: #2196f3; color: white; }
.btn-done { background: #4caf50; color: white; }
</style>
</head>
<body>

<h2>📢 Customer Requests</h2>
<table id="requestsTable">
    <thead>
    <tr>
        <th>Order #</th>
        <th>Table</th>
        <th>Customer</th>
        <th>Request Type</th>
        <th>Status</th>
        <th>Action</th>
    </tr>
    </thead>
    <tbody>
    <?php while($row = $result->fetch_assoc()): ?>
    <tr data-id="<?= htmlspecialchars($row['id']) ?>">
        <td><?= htmlspecialchars($row['order_group_id']) ?></td>
        <td><?= htmlspecialchars($row['table_number']) ?></td>
        <td><?= htmlspecialchars($row['customer_name']) ?></td>
        <td>
<?php
$rt = $row['request_type'] ?? '';
if ($rt === 'Printed Receipt') $rt = 'Ready to Pay';
echo htmlspecialchars($rt);
?>
</td>
        <td class="<?php 
            echo $row['status']=='Pending'?'status-pending':
                 ($row['status']=='Acknowledged'?'status-ack':'status-done'); ?>">
            <?= htmlspecialchars($row['status']) ?>
        </td>
        <td>
            <?php if ($row['status'] == 'Pending'): ?>
                <button class="btn-ack" onclick="updateRequest(<?= $row['id'] ?>, 'Acknowledged', this)">Acknowledge</button>
            <?php endif; ?>
            <?php if ($row['status'] != 'Completed'): ?>
                <button class="btn-done" onclick="updateRequest(<?= $row['id'] ?>, 'Completed', this)">Mark Done</button>
            <?php endif; ?>
        </td>
    </tr>
    <?php endwhile; ?>
    </tbody>
</table>

<script>
function updateRequest(id, newStatus, btn){
    fetch("update_request.php", {
        method: "POST",
        headers: {"Content-Type":"application/x-www-form-urlencoded"},
        body: "id=" + encodeURIComponent(id) + "&status=" + encodeURIComponent(newStatus)
    })
    .then(res => res.json())
    .then(data => {
        if(data.status === "success"){
            // Update the row in place (no page reload)
            const row = document.querySelector('tr[data-id="'+id+'"]');
            if(row){
                // Update the status cell
                const statusCell = row.querySelector('td:nth-child(5)');
                statusCell.textContent = newStatus;
                statusCell.className = (newStatus === "Pending" ? "status-pending" :
                                       (newStatus === "Acknowledged" ? "status-ack" : "status-done"));

                // Update the action cell
                const actionCell = row.querySelector('td:last-child');
                let html = '';
                if(newStatus === "Pending"){
                    html += `<button class="btn-ack" onclick="updateRequest(${id}, 'Acknowledged', this)">Acknowledge</button>`;
                }
                if(newStatus !== "Completed"){
                    html += `<button class="btn-done" onclick="updateRequest(${id}, 'Completed', this)">Mark Done</button>`;
                }
                actionCell.innerHTML = html;
            }
        } else {
            alert("Failed to update request.");
        }
    })
    .catch(err => {
        alert("Error: " + err);
        console.error(err);
    });
}
</script>
</body>
</html>
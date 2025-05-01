<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

function customer_dashboard() {

    if (isset($_GET['delete_customer'])) {
        delete_customer($_GET['delete_customer']);
    }

    $customers = tholaMaCustomer();
    ?>
<div class="wrap p-6">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Customer Dashboard</h1>
        <?php customer_form(); ?>
    </div>

    <div class="overflow-x-auto bg-white shadow-md rounded-xl">
        <table class="min-w-full text-left text-sm text-gray-700">
            <thead class="bg-gray-100 border-b font-semibold uppercase">
                <tr>
                    <th class="px-6 py-4">#</th>
                    <th class="px-6 py-4">Name</th>
                    <th class="px-6 py-4">Email</th>
                    <th class="px-6 py-4">Phone</th>
                    <th class="px-6 py-4">Waybills</th>
                    <th class="px-6 py-4">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                    foreach($customers as $cust):
                   
                    ?>
                <!-- Example row -->
                <tr class="border-b hover:bg-gray-50">
                    <td class="px-6 py-4"><?php echo ($cust->cust_id) ?? 'N/A'; ?></td>
                    <td class="px-6 py-4">
                        <p><?php echo ($cust->name) ?? 'N/A'; ?></p>
                    </td>
                    <td class="px-6 py-4"><?php echo ($cust->surname) ?? 'N/A'; ?></td>
                    <td class="px-6 py-4"><?php echo ($cust->cell) ?? 'N/A'; ?></td>
                    <td class="px-6 py-4">
                        <a href="?page=08600-Waybill&cust_id=<?php echo $cust->cust_id; ?>"
                            class="text-blue-600 hover:underline">
                            Waybills
                        </a>
                    </td>
                    <td class="px-6 py-4 space-x-2">
                        <a href="?page=edit-customer&edit_customer=<?php echo $cust->id; ?>"
                            class="text-blue-600 hover:underline">
                            View
                        </a>
                        <a href="?page=customers-dashboard&delete_customer=<?php echo $cust->id; ?>"
                            class="text-red-600 hover:underline"
                            onclick="return confirm('Are you sure you want to delete this customer?');">
                            Delete
                        </a>
                    </td>
                </tr>
                <?php endforeach ?>
                <!-- More rows will be looped in here -->
            </tbody>
        </table>
    </div>
</div>
<?php
}

function customer_button_with_modal() {
    ?>
<div class="p-6">
    <!-- Trigger Button -->
    <button onclick="document.getElementById('thaboModal').classList.remove('hidden')"
        class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-xl shadow">
        Open Modal
    </button>

    <!-- Modal Overlay -->
    <div id="thaboModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden z-50">
        <!-- Modal Content -->
        <div class="bg-white p-6 rounded-xl shadow-xl w-96 text-center">
            <h2 class="text-xl font-semibold mb-4">Hey Thabo 👋</h2>
            <p class="mb-6">Welcome to the modal!</p>
            <button onclick="document.getElementById('thaboModal').classList.add('hidden')"
                class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-2 rounded-lg">
                Close
            </button>
        </div>
    </div>
</div>
<?php
}

function theForm($customer = null) {
    ?>
<input type="hidden" name="cust_id" id="cust_id" value="<?= esc_attr($customer->cust_id ?? '') ?>">

<div>
    <label class="block text-sm font-medium">Name</label>
    <input type="text" name="name" id="name" value="<?= esc_attr($customer->name ?? '') ?>"
        class="w-full border border-gray-300 rounded px-3 py-2 mt-1" required>
</div>

<div>
    <label class="block text-sm font-medium">Surname</label>
    <input type="text" name="surname" id="surname" value="<?= esc_attr($customer->surname ?? '') ?>"
        class="w-full border border-gray-300 rounded px-3 py-2 mt-1" required>
</div>

<div>
    <label class="block text-sm font-medium">Cell</label>
    <input type="text" name="cell" id="cell" value="<?= esc_attr($customer->cell ?? '') ?>"
        class="w-full border border-gray-300 rounded px-3 py-2 mt-1" required>
</div>

<div>
    <label class="block text-sm font-medium">Address</label>
    <textarea name="address" id="address" class="w-full border border-gray-300 rounded px-3 py-2 mt-1"
        required><?= esc_textarea($customer->address ?? '') ?></textarea>
</div>
<?php 
}
function customer_form() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'kit_customers';

    // Edit mode
    $is_edit = false;
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    $customer = null;

    if ($id) {
        $is_edit = true;
        $customer = $wpdb->get_row("SELECT * FROM $table_name WHERE id = $id");
    }

    // Handle form submit
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['customer_submit'])) {
        save_customer();
    }

    ?>

<!-- Trigger Button -->
<button onclick="document.getElementById('customerModal').classList.remove('hidden')"
    class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-xl shadow">
    + Add New Customer
</button>

<!-- Modal -->
<div id="customerModal" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-50">
    <div class="bg-white p-6 rounded-xl w-full max-w-xl relative">
        <!-- Close Button -->
        <button onclick="document.getElementById('customerModal').classList.add('hidden')"
            class="absolute top-3 right-4 text-gray-600 hover:text-black text-xl">
            &times;
        </button>

        <h2 class="text-xl font-bold mb-4"><?= $is_edit ? 'Edit Customer' : 'Add Customer' ?></h2>

        <form method="post" class="space-y-4">
            <?php theForm(); ?>
            <div class="flex justify-end">
                <button type="submit" name="customer_submit"
                    class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded">
                    <?= $is_edit ? 'Update' : 'Save' ?>
                </button>
            </div>
        </form>
    </div>
</div>

<?php
}

function save_customer() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'kit_customers';

    // Sanitize inputs
    $cust_data = [
        'cust_id'  => rand(1000, 9999),
        'name'     => sanitize_text_field($_POST['name']),
        'surname'  => sanitize_text_field($_POST['surname']),
        'cell'     => sanitize_text_field($_POST['cell']),
        'address'  => sanitize_text_field($_POST['address']),
    ];

    // Insert into DB
    $inserted = $wpdb->insert($table_name, $cust_data);

    if ($inserted) {
        echo '<div class="bg-green-100 text-green-800 p-4 rounded mb-4">Customer saved successfully. 🎉</div>';
        wp_redirect(admin_url('admin.php?page=customers-dashboard'));
        exit;
    } else {
        echo '<div class="bg-red-100 text-red-800 p-4 rounded mb-4">Failed to save customer. 😢</div>';
    }
}

function delete_customer($id) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'kit_customers';

    // Sanitize and delete
    $deleted = $wpdb->delete($table_name, ['id' => intval($id)]);

    if ($deleted) {
        echo '<div class="bg-red-100 text-red-800 p-4 rounded mb-4">Customer deleted successfully. 🗑️</div>';
        wp_redirect(admin_url('admin.php?page=customers-dashboard'));
        exit;

    } else {
        echo '<div class="bg-yellow-100 text-yellow-800 p-4 rounded mb-4">Customer not found or already deleted.</div>';
    }
}

function tholaMaCustomer() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'kit_customers';
    return $wpdb->get_results("SELECT * FROM $table_name");
}

function gamaCustomer() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'kit_customers';
    $results = $wpdb->get_results("SELECT * FROM $table_name");
    $customers = array_map(fn($row) => $row->name, $results);
    return $customers;
}

function edit_customer(){
    global $wpdb;
    $table_name = $wpdb->prefix . 'kit_customers';
    $id = isset($_GET['edit_customer']) ? intval($_GET['edit_customer']) : 0;
    $customer = $wpdb->get_row("SELECT * FROM $table_name WHERE id = $id");
    ?>
<div class="max-w-4xl mx-auto bg-white rounded-xl shadow-lg overflow-hidden p-6 mt-7">
    <h1 class="text-xl font-bold mb-4">Our Client</h1>
    <form method="post" class="space-y-4">
        <?php theForm($customer); ?>

        <div class="flex justify-end">
            <button type="submit" name="customer_submit"
                class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded">
                Update
            </button>
        </div>
    </form>
    </a>
    <?php
}
<?php

function uni_modal($atts)
{
    $name = sanitize_text_field($atts['gama']);
    $pathID = sanitize_text_field($atts['pathid']);
?>
    <button id="openModalBtn" class="px-4 py-2 bg-blue-500 text-white rounded">Add New</button>
    <!-- Modal overlay -->
    <div id="modalOverlay" class="fixed inset-0 bg-black bg-opacity-50 hidden">
        <!-- Modal content -->
        <div id="modal" class="bg-white rounded p-6 max-w-sm mx-auto mt-20 hidden relative">
            <!-- Close button -->
            <span id="closeBtn" class="absolute top-2 right-2 cursor-pointer font-bold">X</span>

            <h1 class="text-2xl font-semibold mb-6">Add Education</h1>
            <!-- Modal content -->
            <form method="POST" action="<?php echo admin_url('admin-post.php'); ?>" class="space-y-4">
                <?php wp_nonce_field('nds_add_education_path_nonce', 'nds_nonce'); ?>
                <label for="path_name" class="block text-sm font-medium text-gray-700">Path Name:</label>
                <input type="text" name="path_name" placeholder="Path Name" required
                    class="w-full p-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" />
                <label for="path_description" class="block text-sm font-medium text-gray-700">Description</label>
                <textarea name="path_description" placeholder="Path Description"
                    class="w-full p-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>

                <input type="submit" name="submit_path" value="Add Path"
                    class="w-full bg-blue-500 text-white py-2 rounded-md hover:bg-blue-600 cursor-pointer" />

                <input type="hidden" name="action" value="nds_add_education_path" />
                <!-- Important hidden field -->
            </form>
        </div>
    </div>
<?php
}
add_shortcode('universalModal', 'uni_modal');


function courseModal($atts)
{
   
    $name = sanitize_text_field($atts['gama']);
    $pathID = sanitize_text_field($atts['pathid']);
?>
    <button id="openModalBtn" class="px-4 py-2 bg-blue-500 text-white rounded">Add New</button>
    <!-- Modal overlay -->
    <div id="modalOverlay" class="fixed inset-0 bg-black bg-opacity-50 hidden">
        <!-- Modal content -->
        <div id="modal" class="bg-white rounded p-6 max-w-[80%] mx-auto mt-20 hidden relative">
            <!-- Close button -->
            <span id="closeBtn" class="absolute top-2 right-2 cursor-pointer font-bold">X</span>

            <h1 class="text-2xl font-semibold mb-6">Add Course</h1>
            <!-- Modal content -->
            <form method="POST" action="<?php echo admin_url('admin-post.php'); ?>">
                <?php
                wp_nonce_field('nds_add_course_nonce', 'nds_add_nonce');
                $typ = "add";
                course_form($typ, null, $pathID, 'nds-edit-program');
                ?>
            </form>
        </div>
    </div>
<?php
}
add_shortcode('universalCourseModal', 'courseModal');

function displayRecipePic($attachment_id)
{
    $image_src = wp_get_attachment_image_src($attachment_id, 'full');
    if ($image_src) {
        echo '<img src="' . $image_src[0] . '" alt="' . $attachment_id . ' " class="inline-block h-10 w-10 rounded-full ring-2 ring-white" />';
    } else {
        echo  'Error displaying image. Please try again.';
    }
}

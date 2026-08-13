<?php
/* Client Selection Template */
if (!defined('ABSPATH')) exit;

get_header();
?>

<div class="jopg-selection-page" style="max-width: 1200px; margin: 0 auto; padding: 20px; min-height: 70vh;">
    <div id="jopg-selection-app" data-token="<?php echo esc_attr(get_query_var('jopg_selection_token')); ?>">
        <p style="text-align:center; padding: 40px;">Loading photos...</p>
    </div>
</div>

<?php get_footer(); ?>

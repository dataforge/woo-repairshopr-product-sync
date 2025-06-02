jQuery(document).ready(function($) {
    // Variables to track sync progress
    let totalProcessed = 0;
    let totalProductCount = 0;
    let totalChanges = 0;
    let inProgress = false;
    
    // Handle start sync button click
    $('#start-ajax-sync').on('click', function(e) {
        e.preventDefault();
        
        if (inProgress) {
            return; // Prevent multiple simultaneous syncs
        }
        
        inProgress = true;
        totalProcessed = 0;
        totalChanges = 0;
        
        // Show progress bar and update status
        $('.repairshopr-progress-container').show();
        $('#repairshopr-sync-status').text(repairshopr_sync.processing_text);
        $('#repairshopr-sync-progress').css('width', '0%').text('0%');
        
        // Start the first batch
        processBatch(0);
    });
    
    // Function to process each batch via AJAX
    function processBatch(batchNumber) {
        $.ajax({
            url: repairshopr_sync.ajax_url,
            type: 'POST',
            data: {
                action: 'repairshopr_process_batch',
                nonce: repairshopr_sync.nonce,
                batch: batchNumber,
                batch_size: repairshopr_sync.batch_size
            },
            success: function(response) {
                if (response.success) {
                    const data = response.data;
                    
                    // On first batch, get the total count
                    if (batchNumber === 0 && data.total) {
                        totalProductCount = data.total;
                    }
                    
                    // Update progress
                    totalProcessed += data.processed;
                    totalChanges += data.changes_count;
                    
                    // Calculate percentage
                    const percentage = Math.min(Math.round((totalProcessed / totalProductCount) * 100), 100);
                    $('#repairshopr-sync-progress').css('width', percentage + '%').text(percentage + '%');
                    
                    // Update status message
                    $('#repairshopr-sync-status').text('Processed ' + totalProcessed + ' of ' + totalProductCount + ' products. Changes: ' + totalChanges);
                    
                    // If more batches exist, process the next one
                    if (data.more && data.next_batch !== null) {
                        processBatch(data.next_batch);
                    } else {
                        // All done
                        inProgress = false;
                        $('#repairshopr-sync-status').text(repairshopr_sync.complete_text + ' Processed ' + totalProcessed + ' products with ' + totalChanges + ' changes.');
                        
                        // Reload page to display results after a short delay
                        setTimeout(function() {
                            window.location.reload();
                        }, 2000);
                    }
                } else {
                    // Error handling
                    inProgress = false;
                    $('#repairshopr-sync-status').text('Error: ' + (response.data || 'Unknown error'));
                }
            },
            error: function(xhr, status, error) {
                inProgress = false;
                $('#repairshopr-sync-status').text('AJAX Error: ' + error);
            }
        });
    }
});

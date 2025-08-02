jQuery(document).ready(function ($) {
  var debounceTimeout; // Variable to store the debounce timer
  var checkoutForm = $("form.woocommerce-checkout"); // Select the WooCommerce checkout form
  var isRequestInProgress = false; // Flag to prevent multiple simultaneous requests
  var lastSentData = ''; // Store last sent data to avoid duplicate requests

  // Ensure we are on the checkout page and the form exists
  if (checkoutForm.length && typeof abandoned_cart_tracker_vars !== 'undefined') {
    
    // Function to send data via AJAX
    function sendCheckoutData() {
      // Prevent multiple simultaneous requests
      if (isRequestInProgress) {
        return;
      }

      try {
        var formData = checkoutForm.serializeArray(); // Serialize form data as an array
        var customerData = {}; // An empty object to store customer data
        var hasValidData = false; // Flag to check if we have any valid data

        // Filter only billing and shipping fields from the serialized data
        $.each(formData, function (i, field) {
          if (field.name && (
            field.name.indexOf("billing_") === 0 ||
            field.name.indexOf("shipping_") === 0
          )) {
            // Only include non-empty values
            if (field.value && field.value.trim() !== '') {
              customerData[field.name] = field.value.trim();
              hasValidData = true;
            }
          }
        });

        // Don't send request if no valid data or if data hasn't changed
        var currentDataString = JSON.stringify(customerData);
        if (!hasValidData || currentDataString === lastSentData) {
          return;
        }

        // Set flag to prevent multiple requests
        isRequestInProgress = true;
        lastSentData = currentDataString;

        // AJAX call with improved error handling
        $.ajax({
          url: abandoned_cart_tracker_vars.ajax_url, // WordPress AJAX URL
          type: "POST", // HTTP method
          timeout: 10000, // 10 second timeout
          data: {
            action: "save_abandoned_checkout_data", // WordPress AJAX action
            customer_data: customerData, // Customer data object
            security: abandoned_cart_tracker_vars.nonce, // Security nonce
          },
          success: function (response) {
            // Reset flag on success
            isRequestInProgress = false;
            
            // Log success for debugging (uncomment if needed)
            // console.log('Abandoned cart data saved/updated:', response);
            
            // Handle server-side errors in success response
            if (response && !response.success && response.data && response.data.message) {
              console.warn('Abandoned Cart Tracker: Server returned error - ' + response.data.message);
            }
          },
          error: function (xhr, status, error) {
            // Reset flag on error
            isRequestInProgress = false;
            
            // Reset last sent data on error so we can retry
            lastSentData = '';
            
            // Log error for debugging (uncomment if needed)
            // console.error('Abandoned Cart Tracker: AJAX error - ' + error);
            
            // Handle specific error cases
            if (status === 'timeout') {
              console.warn('Abandoned Cart Tracker: Request timed out');
            } else if (xhr.status === 403) {
              console.warn('Abandoned Cart Tracker: Security check failed');
            } else if (xhr.status === 0) {
              // Network error or request cancelled
              console.warn('Abandoned Cart Tracker: Network error or request cancelled');
            }
          }
        });
      } catch (e) {
        // Reset flag on exception
        isRequestInProgress = false;
        console.error('Abandoned Cart Tracker: Exception in sendCheckoutData - ' + e.message);
      }
    }

    // Set event listeners for changes or loss of focus on input, select, and textarea fields
    checkoutForm.on("change blur keyup", "input, select, textarea", function (e) {
      // Skip if it's a keyup event on non-text inputs
      if (e.type === 'keyup' && !$(this).is('input[type="text"], input[type="email"], input[type="tel"], textarea')) {
        return;
      }
      
      clearTimeout(debounceTimeout); // Clear the previous debounce timer
      
      // Set a new debounce timer to send data after 2 seconds
      // Increased from 1.5s to reduce server load
      debounceTimeout = setTimeout(function() {
        sendCheckoutData();
      }, 2000);
    });

    // Send data when navigating away from the page
    $(window).on("beforeunload", function () {
      // Clear any pending debounced request
      clearTimeout(debounceTimeout);
      
      // Send data immediately (synchronous for beforeunload)
      if (!isRequestInProgress) {
        try {
          var formData = checkoutForm.serializeArray();
          var customerData = {};
          var hasValidData = false;

          $.each(formData, function (i, field) {
            if (field.name && (
              field.name.indexOf("billing_") === 0 ||
              field.name.indexOf("shipping_") === 0
            )) {
              if (field.value && field.value.trim() !== '') {
                customerData[field.name] = field.value.trim();
                hasValidData = true;
              }
            }
          });

          if (hasValidData) {
            // Use sendBeacon if available for better reliability on page unload
            if (navigator.sendBeacon) {
              var formData = new FormData();
              formData.append('action', 'save_abandoned_checkout_data');
              formData.append('customer_data', JSON.stringify(customerData));
              formData.append('security', abandoned_cart_tracker_vars.nonce);
              
              navigator.sendBeacon(abandoned_cart_tracker_vars.ajax_url, formData);
            } else {
              // Fallback to synchronous AJAX
              $.ajax({
                url: abandoned_cart_tracker_vars.ajax_url,
                type: "POST",
                async: false, // Synchronous for beforeunload
                data: {
                  action: "save_abandoned_checkout_data",
                  customer_data: customerData,
                  security: abandoned_cart_tracker_vars.nonce,
                }
              });
            }
          }
        } catch (e) {
          console.error('Abandoned Cart Tracker: Exception in beforeunload - ' + e.message);
        }
      }
    });

    // Optional: Send data when form is submitted (as additional backup)
    checkoutForm.on("submit", function () {
      clearTimeout(debounceTimeout);
      sendCheckoutData();
    });

  } else {
    // Log warning if required variables are not available
    if (typeof abandoned_cart_tracker_vars === 'undefined') {
      console.warn('Abandoned Cart Tracker: Required JavaScript variables not found');
    }
  }
});

jQuery(document).ready(function ($) {
  var debounceTimeout; // Variable to store the debounce timer
  var checkoutForm = $("form.woocommerce-checkout"); // Select the WooCommerce checkout form

  // Ensure we are on the checkout page and the form exists
  if (checkoutForm.length) {
    // Function to send data via AJAX
    function sendCheckoutData() {
      var formData = checkoutForm.serializeArray(); // Serialize form data as an array
      var customerData = {}; // An empty object to store customer data

      // Filter only billing and shipping fields from the serialized data
      $.each(formData, function (i, field) {
        if (
          field.name.startsWith("billing_") ||
          field.name.startsWith("shipping_")
        ) {
          customerData[field.name] = field.value;
        }
      });

      // AJAX call
      $.ajax({
        url: abandoned_cart_tracker_vars.ajax_url, // WordPress AJAX URL (obtained from wp_localize_script)
        type: "POST", // HTTP method
        data: {
          action: "save_abandoned_checkout_data", // WordPress AJAX action
          customer_data: customerData, // Customer data object
          security: abandoned_cart_tracker_vars.nonce, // Security nonce (obtained from wp_localize_script)
        },
        success: function (response) {
          // Log to console on success (for debugging)
          // console.log('Abandoned cart data saved/updated:', response);
        },
        error: function (xhr, status, error) {
          // Log to console on error (for debugging)
          // console.error('Error saving abandoned cart data:', error);
        },
      });
    }

    // Set event listeners for changes or loss of focus on input, select, and textarea fields in the checkout form
    checkoutForm.on("change blur", "input, select, textarea", function () {
      clearTimeout(debounceTimeout); // Clear the previous debounce timer
      // Set a new debounce timer to send data after 1.5 seconds
      // This helps prevent excessive AJAX requests
      debounceTimeout = setTimeout(sendCheckoutData, 1500);
    });

    // Send data even when navigating away from the page (e.g., closing the browser or going to another page)
    // This ensures that data is saved even if the user fills out the form and leaves the page
    $(window).on("beforeunload", function () {
      sendCheckoutData();
    });
  }
});

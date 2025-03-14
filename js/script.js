// Calculate the base cost based on weight or volume
function calculateBaseCost(weightKg, volumeM3) {
    let baseCost = 0;

    // Weight-based cost calculation
    if (weightKg > 0) {
        if (weightKg <= 500) baseCost = weightKg * 40;
        else if (weightKg <= 1000) baseCost = weightKg * 35;
        else if (weightKg <= 2500) baseCost = weightKg * 30;
        else if (weightKg <= 5000) baseCost = weightKg * 25;
        else if (weightKg <= 7500) baseCost = weightKg * 20;
        else if (weightKg <= 10000) baseCost = weightKg * 17.5;
        else baseCost = weightKg * 15;
    }
    // Volume-based cost calculation
    else if (volumeM3 > 0) {
        if (volumeM3 <= 1) baseCost = 7500;
        else if (volumeM3 <= 2) baseCost = 7000;
        else if (volumeM3 <= 5) baseCost = 6500;
        else if (volumeM3 <= 10) baseCost = 5500;
        else if (volumeM3 <= 15) baseCost = 5000;
        else if (volumeM3 <= 20) baseCost = 4500;
        else if (volumeM3 <= 30) baseCost = 4000;
        else baseCost = 3500;
    }

    return baseCost;
}

// Calculate the total cost based on user inputs and applied fees
function calculateTotalCost() {
    // Get the values from the form
    const weightKg = parseFloat(document.getElementById('weight_kg').value) || 0;
    const volumeM3 = parseFloat(document.getElementById('volume_m3').value) || 0;
    const additionalFees = parseFloat(document.getElementById('additional_fees').value) || 0;
    const discountPercent = parseFloat(document.getElementById('discount_percent').value) || 0;

    // Debug log to check if volume_m3 is being captured
    console.log("Volume (m³): ", volumeM3);  // Debugging line to check volume value

    // Constant costs
    const sad500Fee = 350; // R350
    const sadcCertificateFee = 1000; // R1000
    const traClearingFee = 100 * 18; // $100 converted to R18 (assuming $1 = R18)

    // Checkboxes
    const includeSAD500 = document.getElementById('include_sad500').checked;
    const includeSADC = document.getElementById('include_sadc_certificate').checked;
    const includeTRA = document.getElementById('include_tra_clearing_fee').checked;

    // Calculate base cost based on weight or volume
    let baseCost = calculateBaseCost(weightKg, volumeM3);

    // Add the additional fees to the base cost
    let totalCost = baseCost + additionalFees;

    // Add selected constant costs
    if (includeSAD500) totalCost += sad500Fee;
    if (includeSADC) totalCost += sadcCertificateFee;
    if (includeTRA) totalCost += traClearingFee;

    // Apply the discount
    const discountAmount = (totalCost * discountPercent) / 100;
    const discountedCost = totalCost - discountAmount;

    // Update the fields with the final values
    document.getElementById('total_cost').value = totalCost.toFixed(2);
    document.getElementById('discounted_cost').value = discountedCost.toFixed(2);
    document.getElementById('final_cost').value = discountedCost.toFixed(2);
}

// Attach event listeners for relevant fields to recalculate the cost when values change
function attachEventListeners() {
    const fields = ['weight_kg', 'volume_m3', 'additional_fees', 'discount_percent', 'include_sad500', 'include_sadc_certificate', 'include_tra_clearing_fee'];

    fields.forEach(field => {
        const element = document.getElementById(field);
        element.addEventListener('input', calculateTotalCost);
        element.addEventListener('change', calculateTotalCost); // For checkboxes
    });
}

// Call the function to set up event listeners and initialize values
window.onload = () => {
    attachEventListeners();
    calculateTotalCost();
};

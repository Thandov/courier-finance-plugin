document.addEventListener("DOMContentLoaded", function () {
    function calculateTotal() {
        let weightCost = parseFloat(document.getElementById("weight_cost").value) || 0;
        let volumeCost = parseFloat(document.getElementById("volume_cost").value) || 0;
        let additionalFees = parseFloat(document.getElementById("additional_fees").value) || 0;
        let discountPercent = parseFloat(document.getElementById("discount_percent").value) || 0;

        let finalCost = weightCost + volumeCost + additionalFees;
        let discountedCost = finalCost - (finalCost * (discountPercent / 100));

        document.getElementById("final_cost").value = finalCost.toFixed(2);
        document.getElementById("discounted_cost").value = discountedCost.toFixed(2);
    }

    document.querySelectorAll("input").forEach(input => {
        input.addEventListener("input", calculateTotal);
    });
});
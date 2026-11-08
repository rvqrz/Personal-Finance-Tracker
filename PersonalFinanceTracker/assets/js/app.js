
document.addEventListener("DOMContentLoaded", function () {
    const typeSelect = document.getElementById("type");
    const categorySelect = document.getElementById("category_id");

    if (!typeSelect || !categorySelect) {
        return;
    }

    function filterCategories(resetSelection) {
        const selectedOption = categorySelect.selectedOptions[0];

        if (
            resetSelection &&
            selectedOption &&
            selectedOption.dataset.type &&
            selectedOption.dataset.type !== typeSelect.value
        ) {
            categorySelect.value = "";
        }

        Array.from(categorySelect.options).forEach(function (option) {
            const optionType = option.dataset.type;

            if (!optionType) {
                option.hidden = false;
                option.disabled = false;
                return;
            }

            const isMatchingType = optionType === typeSelect.value;

            option.hidden = !isMatchingType;
            option.disabled = !isMatchingType;
        });
    }

    filterCategories(false);

    typeSelect.addEventListener("change", function () {
        filterCategories(true);
    });
});

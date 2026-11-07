const money = new Intl.NumberFormat("en-US", { style: "currency", currency: "USD" });

export function mountPublicRetailNow() {
    for (const root of document.querySelectorAll("[data-retail-calculator]")) {
        if (root.dataset.retailMounted) continue;
        root.dataset.retailMounted = "true";
        const form = root.querySelector("form");
        const read = (name) => {
            const input = form.elements.namedItem(name);
            const value = input.valueAsNumber;
            return Number.isFinite(value) && value >= Number(input.min) && value <= Number(input.max) ? value : NaN;
        };
        const text = (selector, value) => { root.querySelector(selector).textContent = value; };
        const update = () => {
            const hotelSelected = form.elements.namedItem("includeHotel").checked;
            form.elements.namedItem("hotel").disabled = !hotelSelected;
            const sales = Math.round(read("sales") * 100);
            const booth = Math.round(read("booth") * 100);
            const staff = Math.round(read("hours") * read("pay") * 100);
            const mileage = Math.round(read("miles") * read("mileageRate") * 100);
            const hotel = hotelSelected ? Math.round(read("hotel") * 100) : 0;
            const bps = Math.round((read("cogs") + read("processing")) * 100);
            const valid = [sales, booth, staff, mileage, hotel, bps].every(Number.isFinite) && bps < 10000;
            if (!valid) {
                for (const key of ["breakEven", "costs", "surplus"]) text('[data-retail-result="' + key + '"]', "Review inputs");
                for (const key of ["sales", "booth", "staff", "mileage", "hotel", "variable", "costs", "surplus"]) text('[data-retail-table="' + key + '"]', "Unknown");
                root.querySelector("[data-retail-sales-bar]").setAttribute("width", "0");
                root.querySelector("[data-retail-costs-bar]").setAttribute("width", "0");
                text("[data-retail-chart-description]", "Review the assumptions before calculating this illustration.");
                text("[data-retail-example-status]", "Enter valid nonnegative values. Product and processing percentages together must be below 100%.");
                return;
            }
            const variable = Math.round(sales * bps / 10000);
            const fixed = booth + staff + mileage + hotel;
            const costs = fixed + variable;
            const surplus = sales - costs;
            const breakEven = Math.ceil(fixed * 10000 / (10000 - bps));
            for (const [key, amount] of Object.entries({ breakEven, costs, surplus })) text('[data-retail-result="' + key + '"]', money.format(amount / 100));
            for (const [key, amount] of Object.entries({ sales, booth, staff, mileage, hotel, variable, costs, surplus })) text('[data-retail-table="' + key + '"]', money.format(amount / 100));
            const scale = Math.max(sales, costs, 1);
            root.querySelector("[data-retail-sales-bar]").setAttribute("width", String(460 * sales / scale));
            root.querySelector("[data-retail-costs-bar]").setAttribute("width", String(460 * costs / scale));
            text("[data-retail-chart-description]", "Illustrative assumed sales " + money.format(sales / 100) + ", estimated costs " + money.format(costs / 100) + ", and surplus or shortfall " + money.format(surplus / 100) + ". The adjacent table contains the same assumptions.");
            text("[data-retail-example-status]", "Illustrative planning estimate. Nothing is saved or sent.");
        };
        form.addEventListener("submit", (event) => event.preventDefault());
        form.addEventListener("input", update);
        form.addEventListener("change", update);
        update();
    }
}

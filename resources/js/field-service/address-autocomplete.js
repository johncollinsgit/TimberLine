const suggestionsUrl = "/field-service/address-suggestions";

function inputFor(form, name) {
  return form.querySelector(`[name="${name}"]`);
}

export function mountFieldServiceAddressAutocompleteNow() {
  document
    .querySelectorAll(".field-service-job-shell form[action*='/details'] input[name='service_address_line_1']")
    .forEach((input) => {
      if (input.dataset.addressAutocompleteMounted === "true") return;
      const form = input.closest("form");
      const container = input.closest("[data-address-autocomplete]") || input.parentElement;
      if (!form || !container) return;

      input.dataset.addressAutocompleteMounted = "true";
      input.setAttribute("autocomplete", "street-address");
      input.setAttribute("aria-autocomplete", "list");
      input.setAttribute("aria-expanded", "false");
      container.classList.add("relative");

      let status = container.querySelector("[data-address-status]");
      if (!status) {
        status = document.createElement("span");
        status.dataset.addressStatus = "";
        status.className = "mt-1 block text-xs font-normal text-zinc-500";
        status.textContent = "Choose a suggested address to fill city, state, postal code, and country.";
        input.insertAdjacentElement("afterend", status);
      }

      let choices = container.querySelector("[data-address-suggestions]");
      if (!choices) {
        choices = document.createElement("div");
        choices.dataset.addressSuggestions = "";
        choices.className = "absolute z-20 mt-1 hidden w-full overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-lg";
        choices.setAttribute("role", "listbox");
        status.insertAdjacentElement("afterend", choices);
      }

      let timer;
      let controller;
      const close = () => {
        choices.replaceChildren();
        choices.classList.add("hidden");
        input.setAttribute("aria-expanded", "false");
      };
      const setField = (name, value) => {
        const field = inputFor(form, name);
        if (field && value) field.value = value;
      };
      const select = async (suggestion) => {
        close();
        status.textContent = "Filling in address details…";
        try {
          const response = await fetch(`${suggestionsUrl}/${encodeURIComponent(suggestion.place_id)}`, {
            headers: { Accept: "application/json" },
          });
          const address = (await response.json()).address;
          if (!response.ok || !address) throw new Error("No address details");
          setField("service_address_line_1", address.line_1 || suggestion.label);
          setField("service_address_line_2", address.line_2);
          setField("service_city", address.city);
          setField("service_state", address.state);
          setField("service_postal_code", address.postal_code);
          setField("service_country", address.country);
          status.textContent = "Address details filled in. Review them, then save the job.";
        } catch (_) {
          input.value = suggestion.label;
          status.textContent = "Address selected. Complete any missing details, then save the job.";
        }
      };
      const search = async () => {
        const query = input.value.trim();
        if (query.length < 4) return close();
        controller?.abort();
        controller = new AbortController();
        try {
          const response = await fetch(`${suggestionsUrl}?q=${encodeURIComponent(query)}`, {
            headers: { Accept: "application/json" },
            signal: controller.signal,
          });
          const suggestions = (await response.json()).suggestions || [];
          if (!response.ok || !suggestions.length) return close();
          choices.replaceChildren(...suggestions.map((suggestion) => {
            const button = document.createElement("button");
            button.type = "button";
            button.className = "block w-full border-b border-zinc-100 px-3 py-2 text-left text-sm font-normal text-zinc-800 last:border-b-0 hover:bg-emerald-50 focus:bg-emerald-50";
            button.textContent = suggestion.label;
            button.setAttribute("role", "option");
            button.addEventListener("click", () => void select(suggestion));
            return button;
          }));
          choices.classList.remove("hidden");
          input.setAttribute("aria-expanded", "true");
        } catch (error) {
          if (error.name !== "AbortError") close();
        }
      };

      input.addEventListener("input", () => {
        clearTimeout(timer);
        timer = setTimeout(() => void search(), 250);
      });
      input.addEventListener("keydown", (event) => { if (event.key === "Escape") close(); });
      document.addEventListener("click", (event) => { if (!container.contains(event.target)) close(); });
    });
}

(function () {
  function withDomReady(callback) {
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", callback, { once: true });
      return;
    }
    callback();
  }

  function ensureLeafletCss() {
    if (document.getElementById("leaflet-css-cdn")) {
      return;
    }

    const link = document.createElement("link");
    link.id = "leaflet-css-cdn";
    link.rel = "stylesheet";
    link.href = "https://unpkg.com/leaflet@1.9.4/dist/leaflet.css";
    link.crossOrigin = "";
    document.head.appendChild(link);
  }

  function ensureLeafletScript() {
    if (window.L && typeof window.L.map === "function") {
      return Promise.resolve(window.L);
    }

    return new Promise(function (resolve, reject) {
      const existing = document.getElementById("leaflet-js-cdn");
      if (existing) {
        existing.addEventListener(
          "load",
          function () {
            resolve(window.L);
          },
          { once: true },
        );
        existing.addEventListener("error", reject, { once: true });
        return;
      }

      const script = document.createElement("script");
      script.id = "leaflet-js-cdn";
      script.src = "https://unpkg.com/leaflet@1.9.4/dist/leaflet.js";
      script.crossOrigin = "";
      script.onload = function () {
        resolve(window.L);
      };
      script.onerror = reject;
      document.head.appendChild(script);
    });
  }

  function ensureLocationFullscreenStyles() {
    if (document.getElementById("supplier-location-fullscreen-styles")) {
      return;
    }

    const style = document.createElement("style");
    style.id = "supplier-location-fullscreen-styles";
    style.textContent = [
      "[data-supplier-location-root].is-location-fullscreen{position:fixed;inset:0;z-index:9999;background:#fff;padding:10px 12px 12px;overflow:hidden;border-radius:0;box-shadow:none;}",
      "[data-supplier-location-root].is-location-fullscreen>div{height:calc(100vh - 24px);display:grid;grid-template-columns:minmax(260px,320px) minmax(0,1fr)!important;gap:12px;align-items:stretch;}",
      "[data-supplier-location-root].is-location-fullscreen>div>:first-child{max-width:320px;overflow:auto;padding-right:4px;}",
      "[data-supplier-location-root].is-location-fullscreen>div>:last-child{display:flex;flex-direction:column;min-width:0;min-height:0;}",
      "[data-supplier-location-root].is-location-fullscreen [data-location-map]{flex:1 1 auto;height:auto!important;min-height:0;border-radius:20px;}",
      "[data-supplier-location-root].is-location-fullscreen [data-location-results]{max-height:28vh;overflow:auto;}",
      "[data-supplier-location-root].is-location-fullscreen [data-location-selection-label]{font-size:13px;line-height:1.35;}",
      "[data-supplier-location-root].is-location-fullscreen [data-location-coordinates],[data-supplier-location-root].is-location-fullscreen [data-location-status]{margin-top:8px;padding-top:10px;padding-bottom:10px;}",
      "[data-supplier-location-root]:fullscreen{background:#fff;padding:10px 12px 12px;overflow:hidden;}",
      "[data-supplier-location-root]:fullscreen>div{height:calc(100vh - 24px);display:grid;grid-template-columns:minmax(260px,320px) minmax(0,1fr)!important;gap:12px;align-items:stretch;}",
      "[data-supplier-location-root]:fullscreen>div>:first-child{max-width:320px;overflow:auto;padding-right:4px;}",
      "[data-supplier-location-root]:fullscreen>div>:last-child{display:flex;flex-direction:column;min-width:0;min-height:0;}",
      "[data-supplier-location-root]:fullscreen [data-location-map]{flex:1 1 auto;height:auto!important;min-height:0;border-radius:20px;}",
      "[data-supplier-location-root]:fullscreen [data-location-results]{max-height:28vh;overflow:auto;}",
      "[data-supplier-location-root]:fullscreen [data-location-selection-label]{font-size:13px;line-height:1.35;}",
      "[data-supplier-location-root]:fullscreen [data-location-coordinates],[data-supplier-location-root]:fullscreen [data-location-status]{margin-top:8px;padding-top:10px;padding-bottom:10px;}",
      "@media (max-width: 960px){[data-supplier-location-root].is-location-fullscreen>div,[data-supplier-location-root]:fullscreen>div{grid-template-columns:minmax(0,1fr)!important;grid-template-rows:auto minmax(0,1fr);overflow:auto;}[data-supplier-location-root].is-location-fullscreen>div>:first-child,[data-supplier-location-root]:fullscreen>div>:first-child{max-width:none;overflow:visible;padding-right:0;}[data-supplier-location-root].is-location-fullscreen [data-location-map],[data-supplier-location-root]:fullscreen [data-location-map]{height:58vh!important;min-height:360px;}}",
    ].join("\n");
    document.head.appendChild(style);
  }

  function escapeHtml(value) {
    return String(value || "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function debounce(fn, delay) {
    let timer = null;
    return function () {
      const args = arguments;
      const context = this;
      window.clearTimeout(timer);
      timer = window.setTimeout(function () {
        fn.apply(context, args);
      }, delay);
    };
  }

  function pickCity(details) {
    if (!details || typeof details !== "object") {
      return "";
    }
    return (
      details.city ||
      details.town ||
      details.village ||
      details.municipality ||
      details.county ||
      ""
    );
  }

  function buildResultHtml(item) {
    const address = item.address || {};
    const city = pickCity(address);
    return [
      '<div class="text-sm font-semibold text-slate-900">' +
        escapeHtml(item.display_name || "Localisation") +
        "</div>",
      city
        ? '<div class="mt-1 text-xs text-slate-500">Ville: ' +
          escapeHtml(city) +
          "</div>"
        : "",
      address.country
        ? '<div class="text-xs text-slate-500">Pays: ' +
          escapeHtml(address.country) +
          "</div>"
        : "",
    ].join("");
  }

  function setFullscreenButtonState(button, active) {
    if (!button) {
      return;
    }

    button.innerHTML = active
      ? '<i class="fas fa-compress" aria-hidden="true"></i>'
      : '<i class="fas fa-expand" aria-hidden="true"></i>';
    button.setAttribute(
      "title",
      active ? "Revenir a l'affichage normal" : "Afficher en plein ecran",
    );
    button.setAttribute(
      "aria-label",
      active ? "Revenir a l'affichage normal" : "Afficher en plein ecran",
    );
  }

  window.initSupplierLocationPicker = function initSupplierLocationPicker(
    config,
  ) {
    withDomReady(function () {
      const root = document.querySelector(
        config.rootSelector || "[data-supplier-location-root]",
      );
      if (!root || root.dataset.locationPickerMounted === "1") {
        return;
      }
      root.dataset.locationPickerMounted = "1";

      const settings = config.settings || {};
      const searchInput = root.querySelector("[data-location-search]");
      const resultsNode = root.querySelector("[data-location-results]");
      const form = root.closest("form") || document;
      const addressField = form.querySelector("[name='adresse']");
      const cityField = form.querySelector("[name='ville']");
      const countryField = form.querySelector("[name='pays']");
      const fiscalCountry = form.querySelector("[name='fiscal_country']");
      const scopeLabel = document.createElement('label');
      scopeLabel.className = 'block mb-3 text-sm font-medium';
      scopeLabel.textContent = 'Zone de recherche des adresses';
      const scope = document.createElement('select');
      scope.className = 'mt-1 block w-full rounded-xl border px-3 py-2 bg-white';
      scope.innerHTML = '<option value="supplier">Pays fiscal du fournisseur</option><option value="all">Monde entier</option>';
      scopeLabel.appendChild(scope);
      const credit=document.createElement('small');credit.textContent='Suggestions : Photon / © OpenStreetMap contributors';scopeLabel.appendChild(credit);
      searchInput?.parentNode.insertBefore(scopeLabel, searchInput);
      const searchCountry = () => scope.value === 'all' ? 'all' : (fiscalCountry?.value || countryField?.value || 'CI').trim().toUpperCase();
      const mapNode = root.querySelector("[data-location-map]");
      const statusNode = root.querySelector("[data-location-status]");
      const selectionNode = root.querySelector(
        "[data-location-selection-label]",
      );
      const sourceBadgeNode = root.querySelector(
        "[data-location-source-badge]",
      );
      const clearButton = root.querySelector("[data-location-clear]");
      const useCurrentButton = root.querySelector("[data-location-current]");
      const recenterButton = root.querySelector("[data-location-recenter]");
      const fullscreenButton = root.querySelector("[data-location-fullscreen]");
      const coordinatesNode = root.querySelector("[data-location-coordinates]");
      const apiUrl = config.apiUrl || "../api/location_lookup.php";
      const latitudeField = form.querySelector("[name='location_latitude']");
      const longitudeField = form.querySelector("[name='location_longitude']");
      const sourceField = form.querySelector("[name='location_source']");
      const labelField = form.querySelector("[name='location_label']");

      if (!searchInput || !addressField || !mapNode) {
        return;
      }

      const state = {
        map: null,
        marker: null,
        items: [],
        selected: null,
        requestId: 0,
        activeIndex: -1,
      };

      function setStatus(message, tone) {
        if (!statusNode) {
          return;
        }
        statusNode.textContent = message;
        statusNode.className =
          "mt-3 rounded-2xl border px-4 py-3 text-xs " +
          ({
            success: "border-emerald-200 bg-emerald-50 text-emerald-700",
            warning: "border-amber-200 bg-amber-50 text-amber-700",
            danger: "border-rose-200 bg-rose-50 text-rose-700",
            info: "border-sky-200 bg-sky-50 text-sky-700",
            neutral: "border-slate-200 bg-white text-slate-600",
          }[tone || "neutral"] || "border-slate-200 bg-white text-slate-600");
      }

      function setSelectionText(message) {
        if (!selectionNode) {
          return;
        }
        selectionNode.textContent = message;
      }

      function setSourceBadge(source) {
        if (!sourceBadgeNode) {
          return;
        }

        const tone =
          source === "remote"
            ? "border-emerald-200 bg-emerald-50 text-emerald-700"
            : source === "browser"
              ? "border-sky-200 bg-sky-50 text-sky-700"
              : source === "local"
                ? "border-amber-200 bg-amber-50 text-amber-700"
                : "border-slate-200 bg-white text-slate-500";
        const label =
          source === "remote"
            ? "Source carte"
            : source === "browser"
              ? "GPS navigateur"
              : source === "local"
                ? "Source locale"
                : "Aucune source";

        sourceBadgeNode.className =
          "inline-flex rounded-full border px-2.5 py-1 text-[11px] font-semibold " +
          tone;
        sourceBadgeNode.textContent = label;
      }

      function setHiddenPositionFields(item) {
        if (latitudeField) {
          latitudeField.value =
            item && item.lat !== null && item.lat !== undefined
              ? String(item.lat)
              : "";
        }
        if (longitudeField) {
          longitudeField.value =
            item && item.lon !== null && item.lon !== undefined
              ? String(item.lon)
              : "";
        }
        if (sourceField) {
          sourceField.value = item && item.source ? String(item.source) : "";
        }
        if (labelField) {
          labelField.value =
            item && item.display_name ? String(item.display_name) : "";
        }
        setSourceBadge(item && item.source ? String(item.source) : "");
        if (coordinatesNode) {
          coordinatesNode.textContent =
            item && item.lat !== null && item.lon !== null
              ? "Lat " +
                Number(item.lat).toFixed(6) +
                " | Lng " +
                Number(item.lon).toFixed(6)
              : "Coordonnées non fixées";
        }
      }

      function tileLayer() {
        return window.L.tileLayer(
          settings.tile_url ||
            "https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png",
          {
            attribution:
              settings.tile_attribution || "&copy; OpenStreetMap contributors",
            maxZoom: 19,
          },
        );
      }

      function ensureMap() {
        if (state.map) {
          return Promise.resolve(state.map);
        }

        ensureLocationFullscreenStyles();
        ensureLeafletCss();
        return ensureLeafletScript().then(function () {
          state.map = window.L.map(mapNode, {
            zoomControl: true,
            scrollWheelZoom: true,
          }).setView(
            [
              Number(settings.map_center_lat || 5.348),
              Number(settings.map_center_lng || -4.03),
            ],
            Number(settings.map_zoom || 7),
          );
          tileLayer().addTo(state.map);
          window.setTimeout(function () {
            state.map.invalidateSize();
          }, 250);
          return state.map;
        });
      }

      function syncFullscreenButton() {
        if (!fullscreenButton) {
          return;
        }
        const active =
          document.fullscreenElement === root ||
          root.classList.contains("is-location-fullscreen");
        setFullscreenButtonState(fullscreenButton, active);
      }

      function refreshMapSize() {
        if (!state.map) {
          return;
        }
        window.setTimeout(function () {
          state.map.invalidateSize();
        }, 180);
      }

      function toggleFullscreen() {
        if (document.fullscreenElement === root && document.exitFullscreen) {
          document.exitFullscreen().finally(function () {
            syncFullscreenButton();
            refreshMapSize();
          });
          return;
        }

        if (!document.fullscreenElement && root.requestFullscreen) {
          root
            .requestFullscreen()
            .then(function () {
              syncFullscreenButton();
              refreshMapSize();
            })
            .catch(function () {
              root.classList.toggle("is-location-fullscreen");
              syncFullscreenButton();
              refreshMapSize();
            });
          return;
        }

        root.classList.toggle("is-location-fullscreen");
        syncFullscreenButton();
        refreshMapSize();
      }

      function setMarker(lat, lng, label) {
        if (
          lat === null ||
          lng === null ||
          !isFinite(Number(lat)) ||
          !isFinite(Number(lng))
        ) {
          return;
        }
        ensureMap().then(function () {
          const point = [Number(lat), Number(lng)];
          if (!state.marker) {
            state.marker = window.L.marker(point, { draggable: true }).addTo(
              state.map,
            );
            state.marker.on("dragend", function (event) {
              const markerLatLng = event.target.getLatLng();
              const adjustedItem = {
                display_name:
                  (labelField && labelField.value) ||
                  (state.selected && state.selected.display_name) ||
                  "Position ajustée",
                lat: markerLatLng.lat,
                lon: markerLatLng.lng,
                source: "manual",
                address:
                  state.selected && state.selected.address
                    ? state.selected.address
                    : {},
              };
              state.selected = adjustedItem;
              setHiddenPositionFields(adjustedItem);
              setSelectionText(adjustedItem.display_name);
              setStatus(
                "Position ajustée manuellement sur la carte.",
                "success",
              );
            });
          } else {
            state.marker.setLatLng(point);
          }
          if (label) {
            state.marker.bindPopup(
              '<div class="text-sm font-semibold">' +
                escapeHtml(label) +
                "</div>",
            );
          }
          state.map.setView(
            point,
            Math.max(Number(settings.map_zoom || 7), 14),
            {
              animate: true,
            },
          );

          if (!state.map._banamurClickBound) {
            state.map._banamurClickBound = true;
            state.map.on("click", function (event) {
              const clicked = event.latlng;
              const manualItem = {
                display_name:
                  (labelField && labelField.value) ||
                  (state.selected && state.selected.display_name) ||
                  "Point ajusté manuellement",
                lat: clicked.lat,
                lon: clicked.lng,
                source: "manual",
                address:
                  state.selected && state.selected.address
                    ? state.selected.address
                    : {},
              };
              state.selected = manualItem;
              setHiddenPositionFields(manualItem);
              setMarker(clicked.lat, clicked.lng, manualItem.display_name);
              setStatus(
                "Marker repositionné par clic sur la carte.",
                "success",
              );
            });
          }
        });
      }

      function clearResults() {
        state.items = [];
        state.activeIndex = -1;
        if (resultsNode) {
          resultsNode.innerHTML = "";
        }
      }

      function highlightActiveResult() {
        if (!resultsNode) {
          return;
        }
        resultsNode
          .querySelectorAll("[data-location-result-index]")
          .forEach(function (button, index) {
            const active = index === state.activeIndex;
            button.classList.toggle("border-cyan-400", active);
            button.classList.toggle("bg-cyan-50", active);
          });
      }

      function selectItem(item) {
        if (!item) {
          return;
        }
        state.selected = item;
        addressField.value = item.display_name || "";
        searchInput.value = item.display_name || "";
        setHiddenPositionFields(item);

        const address = item.address || {};
        const city = pickCity(address);
        if (cityField && city) {
          cityField.value = city;
        }
        if (countryField && address.country_code) {
          countryField.value = String(address.country_code).toUpperCase();
        }

        setSelectionText(item.display_name || "Localisation sélectionnée");
        setStatus(
          item.source === "local"
            ? "Suggestion locale retenue. La carte s'affichera dès qu'une coordonnée sera connue."
            : "Localisation sélectionnée et prête à l'enregistrement.",
          item.source === "local" ? "warning" : "success",
        );
        clearResults();
        if (item.lat !== null && item.lon !== null) {
          setMarker(item.lat, item.lon, item.display_name || "Localisation");
        } else if ((item.display_name || "").length >= 5) {
          searchPlaces(item.display_name || "", true);
        }
      }

      function renderResults(items) {
        if (!resultsNode) {
          return;
        }
        state.activeIndex = items.length ? 0 : -1;
        if (!items.length) {
          resultsNode.innerHTML =
            '<div class="rounded-2xl border border-dashed border-slate-200 px-4 py-3 text-sm text-slate-500">Aucune suggestion trouvée. Essayez avec une commune, un quartier ou une rue.</div>';
          return;
        }

        resultsNode.innerHTML = items
          .map(function (item, index) {
            return (
              '<button type="button" class="block w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-left transition hover:border-cyan-200 hover:bg-cyan-50" data-location-result-index="' +
              index +
              '">' +
              '<div class="flex items-start justify-between gap-3">' +
              '<div class="min-w-0 flex-1">' +
              buildResultHtml(item) +
              "</div>" +
              '<span class="inline-flex rounded-full border px-2 py-1 text-[11px] font-semibold ' +
              (item.source === "local"
                ? "border-amber-200 bg-amber-50 text-amber-700"
                : "border-emerald-200 bg-emerald-50 text-emerald-700") +
              '">' +
              escapeHtml(item.source === "local" ? "Local" : "Carte") +
              "</span>" +
              "</div>" +
              "</button>"
            );
          })
          .join("");

        resultsNode
          .querySelectorAll("[data-location-result-index]")
          .forEach(function (button) {
            button.addEventListener("mouseenter", function () {
              state.activeIndex = Number(
                button.getAttribute("data-location-result-index") || "-1",
              );
              highlightActiveResult();
            });
            button.addEventListener("click", function () {
              const index = Number(
                button.getAttribute("data-location-result-index") || "-1",
              );
              selectItem(state.items[index] || null);
            });
          });
        highlightActiveResult();
      }

      function searchPlaces(query, silent) {
        const currentRequestId = ++state.requestId;
        const cleanQuery = String(query || "").trim();
        if (cleanQuery.length < 3) {
          clearResults();
          if (!silent) {
            setStatus(
              "Saisissez au moins 3 caractères pour rechercher une localisation.",
              "neutral",
            );
          }
          return;
        }
        if (settings.api_enabled === false) {
          setStatus(
            "L'autocomplétion est désactivée dans le paramétrage GPS.",
            "warning",
          );
          clearResults();
          return;
        }

        if (!silent) {
          setStatus("Recherche des suggestions de localisation...", "info");
        }

        const url =
          apiUrl + "?action=search&q=" + encodeURIComponent(cleanQuery) + "&country=" + encodeURIComponent(searchCountry());

        fetch(url, {
          headers: {
            Accept: "application/json",
          },
          credentials: "same-origin",
        })
          .then(function (response) {
            if (!response.ok) {
              throw new Error("Réponse de recherche invalide");
            }
            return response.json();
          })
          .then(function (json) {
            if (currentRequestId !== state.requestId) {
              return;
            }
            state.items = Array.isArray(json && json.items) ? json.items : [];
            renderResults(state.items);
            setStatus(
              state.items.length
                ? json && json.fallback_used
                  ? "Suggestions locales affichées en secours."
                  : "Choisissez une suggestion pour positionner le fournisseur."
                : "Aucune suggestion disponible pour cette saisie.",
              state.items.length
                ? json && json.fallback_used
                  ? "warning"
                  : "success"
                : "warning",
            );
          })
          .catch(function () {
            if (currentRequestId !== state.requestId) {
              return;
            }
            clearResults();
            setStatus(
              "Impossible de charger les suggestions de localisation.",
              "danger",
            );
          });
      }

      function reverseGeocode(lat, lng) {
        const url =
          apiUrl +
          "?action=reverse&lat=" +
          encodeURIComponent(lat) +
          "&lon=" +
          encodeURIComponent(lng);

        return fetch(url, {
          headers: {
            Accept: "application/json",
          },
          credentials: "same-origin",
        })
          .then(function (response) {
            if (!response.ok) {
              throw new Error("Reverse geocoding impossible");
            }
            return response.json();
          })
          .then(function (json) {
            return json && json.item ? json.item : null;
          });
      }

      if (useCurrentButton) {
        useCurrentButton.addEventListener("click", function () {
          if (!navigator.geolocation) {
            setStatus(
              "Le navigateur ne fournit pas la géolocalisation.",
              "warning",
            );
            return;
          }

          setStatus("Lecture de votre position actuelle...", "info");
          navigator.geolocation.getCurrentPosition(
            function (position) {
              const lat = position.coords.latitude;
              const lng = position.coords.longitude;
              setMarker(lat, lng, "Position actuelle");
              reverseGeocode(lat, lng)
                .then(function (item) {
                  if (item) {
                    selectItem({
                      display_name: item.display_name || "Position actuelle",
                      lat: lat,
                      lon: lng,
                      address: item.address || {},
                      source: "remote",
                    });
                    return;
                  }
                  setSelectionText("Position actuelle placée sur la carte.");
                  setStatus(
                    "Position actuelle récupérée, sans adresse détaillée.",
                    "warning",
                  );
                })
                .catch(function () {
                  setSelectionText("Position actuelle placée sur la carte.");
                  setStatus(
                    "Position actuelle récupérée, sans adresse détaillée.",
                    "warning",
                  );
                });
            },
            function () {
              setStatus(
                "Impossible d'accéder à la position actuelle.",
                "danger",
              );
            },
            {
              enableHighAccuracy: true,
              timeout: 10000,
              maximumAge: 0,
            },
          );
        });
      }

      if (clearButton) {
        clearButton.addEventListener("click", function () {
          searchInput.value = "";
          addressField.value = "";
          clearResults();
          setHiddenPositionFields(null);
          setSelectionText("Aucune localisation confirmée.");
          setStatus("Recherche réinitialisée.", "neutral");
        });
      }

      if (recenterButton) {
        recenterButton.addEventListener("click", function () {
          if (
            state.selected &&
            state.selected.lat !== null &&
            state.selected.lon !== null
          ) {
            setMarker(
              state.selected.lat,
              state.selected.lon,
              state.selected.display_name || "Localisation",
            );
            setStatus("Carte recentrée sur la sélection active.", "info");
            return;
          }

          ensureMap().then(function () {
            state.map.setView(
              [
                Number(settings.map_center_lat || 5.348),
                Number(settings.map_center_lng || -4.03),
              ],
              Number(settings.map_zoom || 7),
            );
            setStatus("Carte recentrée sur la zone par défaut.", "info");
          });
        });
      }

      if (fullscreenButton) {
        fullscreenButton.addEventListener("click", toggleFullscreen);
        document.addEventListener("fullscreenchange", function () {
          syncFullscreenButton();
          refreshMapSize();
        });
        syncFullscreenButton();
      }

      const delayedSearch = debounce(function () { searchPlaces(searchInput.value, false); }, 650);
      function refreshSearchCountry() {
        state.requestId++;
        clearResults();
        searchInput.placeholder = scope.value === 'all' ? 'Adresse, ville et pays (monde entier)' : 'Adresse dans le pays du fournisseur';
        setStatus('Zone de recherche actualisée. Sélectionnez une suggestion pour confirmer la position.', 'neutral');
      }
      scope.addEventListener('change', refreshSearchCountry);
      function invalidatePosition() {
        state.requestId++;
        state.selected=null;
        setHiddenPositionFields(null);
        if(state.marker && state.map){state.map.removeLayer(state.marker);state.marker=null;}
        clearResults();
        setSelectionText('Adresse modifiée : position à confirmer.');
      }
      let lastFiscalCountry=fiscalCountry?.value;
      fiscalCountry?.addEventListener('change', function () {
        if(fiscalCountry.value!==lastFiscalCountry){invalidatePosition();lastFiscalCountry=fiscalCountry.value;}
        refreshSearchCountry();
      });
      addressField.addEventListener('change', function () {
        invalidatePosition();
        searchInput.value=addressField.value;
        if(searchInput.value.trim().length>=3)searchPlaces(searchInput.value,true);
      });
      refreshSearchCountry();
      searchInput.addEventListener(
        "input",
        function () { state.requestId++; delayedSearch(); },
      );

      searchInput.addEventListener("keydown", function (event) {
        if (!state.items.length) {
          if (event.key === "Escape") {
            clearResults();
          }
          return;
        }

        if (event.key === "ArrowDown") {
          event.preventDefault();
          state.activeIndex = Math.min(
            state.activeIndex + 1,
            state.items.length - 1,
          );
          highlightActiveResult();
          return;
        }

        if (event.key === "ArrowUp") {
          event.preventDefault();
          state.activeIndex = Math.max(state.activeIndex - 1, 0);
          highlightActiveResult();
          return;
        }

        if (event.key === "Enter") {
          event.preventDefault();
          if (state.activeIndex >= 0 && state.items[state.activeIndex]) {
            selectItem(state.items[state.activeIndex]);
          }
          return;
        }

        if (event.key === "Escape") {
          clearResults();
          setStatus("Suggestions fermées.", "neutral");
        }
      });

      ensureMap()
        .then(function () {
          const initialPosition = config.initialPosition || null;
          if (
            initialPosition &&
            typeof initialPosition.latitude === "number" &&
            typeof initialPosition.longitude === "number"
          ) {
            setHiddenPositionFields({
              lat: initialPosition.latitude,
              lon: initialPosition.longitude,
              source: initialPosition.source || "remote",
              display_name:
                initialPosition.location_label ||
                config.initialAddress ||
                "Localisation existante",
            });
            setMarker(
              initialPosition.latitude,
              initialPosition.longitude,
              initialPosition.location_label ||
                config.initialAddress ||
                "Localisation existante",
            );
            setSelectionText(
              initialPosition.location_label ||
                config.initialAddress ||
                "Localisation existante",
            );
            setStatus("Position existante chargée.", "success");
            return;
          }

          if (config.initialAddress) {
            setHiddenPositionFields({
              lat: null,
              lon: null,
              source: "",
              display_name: config.initialAddress,
            });
            setSelectionText(config.initialAddress);
            setStatus(
              "Adresse existante chargée. Sélectionnez une suggestion pour confirmer sa position sur la carte.",
              "info",
            );
            searchPlaces(config.initialAddress, true);
          } else {
            setHiddenPositionFields(null);
            setStatus(
              "Recherchez une localisation pour afficher la carte fournisseur.",
              "neutral",
            );
          }
        })
        .catch(function () {
          setStatus("Chargement de la carte impossible.", "danger");
        });
    });
  };
})();


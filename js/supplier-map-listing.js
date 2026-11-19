(function () {
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

  function ensureMapFullscreenStyles() {
    if (document.getElementById("supplier-map-fullscreen-styles")) {
      return;
    }

    const style = document.createElement("style");
    style.id = "supplier-map-fullscreen-styles";
    style.textContent = [
      "[data-map-shell].is-map-fullscreen{position:fixed;inset:0;z-index:9999;background:#fff;padding:8px 10px 10px;border-radius:0;box-shadow:none;display:flex;flex-direction:column;}",
      "[data-map-shell].is-map-fullscreen #supplier-map{height:calc(100vh - 64px)!important;flex:1 1 auto;}",
      "[data-map-shell].is-map-fullscreen>div:first-child{padding:8px 6px 10px;}",
      "[data-map-shell]:fullscreen{background:#fff;padding:8px 10px 10px;display:flex;flex-direction:column;}",
      "[data-map-shell]:fullscreen #supplier-map{height:calc(100vh - 64px)!important;flex:1 1 auto;}",
      "[data-map-shell]:fullscreen>div:first-child{padding:8px 6px 10px;}",
      ".supplier-map-name-tooltip{background:rgba(15,23,42,.92);border:0;color:#fff;border-radius:999px;box-shadow:0 8px 20px rgba(15,23,42,.18);font-weight:700;padding:4px 10px;white-space:nowrap;}",
      ".supplier-map-name-tooltip:before{display:none;}",
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

  function toneColor(item) {
    if (item.type === "physique") {
      return item.document_is_complete ? "#0f766e" : "#0ea5e9";
    }
    return item.document_is_complete ? "#2563eb" : "#f59e0b";
  }

  function popupHtml(item) {
    const position = item.position || {};
    const missing = Array.isArray(item.missing_document_labels)
      ? item.missing_document_labels
      : [];
    return [
      '<div style="min-width:220px">',
      '<div style="font-weight:700;color:#0f172a">' +
        escapeHtml(item.name) +
        "</div>",
      '<div style="margin-top:4px;font-size:12px;color:#475569">' +
        escapeHtml(item.type_label || "") +
        "</div>",
      item.contact
        ? '<div style="margin-top:8px;font-size:12px;color:#334155">Contact : ' +
          escapeHtml(item.contact) +
          "</div>"
        : "",
      item.telephone
        ? '<div style="font-size:12px;color:#334155">Téléphone : ' +
          escapeHtml(item.telephone) +
          "</div>"
        : "",
      item.address
        ? '<div style="margin-top:6px;font-size:12px;color:#475569">' +
          escapeHtml(item.address) +
          "</div>"
        : "",
      '<div style="margin-top:8px;font-size:12px;color:#0f172a;font-weight:600">' +
        escapeHtml(item.document_completion_label || "") +
        "</div>",
      missing.length
        ? '<div style="margin-top:4px;font-size:11px;color:#b45309">Pièces manquantes : ' +
          escapeHtml(missing.join(", ")) +
          "</div>"
        : "",
      position.updated_at
        ? '<div style="margin-top:6px;font-size:11px;color:#64748b">Maj : ' +
          escapeHtml(position.updated_at) +
          "</div>"
        : "",
      "</div>",
    ].join("");
  }

  function markerIcon(item) {
    const color = toneColor(item);
    return window.L.divIcon({
      className: "supplier-map-marker",
      html:
        '<div style="display:flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:999px;background:' +
        color +
        ';box-shadow:0 0 0 5px rgba(255,255,255,.88),0 8px 20px rgba(15,23,42,.18)"></div>',
      iconSize: [18, 18],
      iconAnchor: [9, 9],
      popupAnchor: [0, -12],
    });
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

  window.initSupplierListingMap = function initSupplierListingMap(config) {
    const run = function () {
      const container = document.getElementById(
        config.containerId || "supplier-map",
      );
      const fullscreenButton = config.fullscreenButtonId
        ? document.getElementById(config.fullscreenButtonId)
        : null;
      const statusNode = document.getElementById(
        config.statusId || "supplier-map-status",
      );
      const countNode = document.getElementById(
        config.localizedCountId || "supplier-map-count",
      );
      if (!container) {
        return;
      }

      const state = {
        settings: config.settings || {},
        items: Array.isArray(config.items) ? config.items : [],
        map: null,
        markers: new Map(),
        bounds: null,
        shell:
          container.closest("[data-map-shell]") ||
          container.parentElement ||
          container,
      };

      function fitAllPositioned(bounds) {
        if (!state.map || !bounds.length) {
          return;
        }

        if (bounds.length === 1) {
          state.map.setView(
            bounds[0],
            Math.max(Number(state.settings.map_zoom || 6), 12),
            { animate: true },
          );
          return;
        }

        state.map.fitBounds(bounds, {
          padding: [42, 42],
          maxZoom: 14,
          animate: true,
        });
      }

      function syncFullscreenButton() {
        if (!fullscreenButton) {
          return;
        }
        const active =
          document.fullscreenElement === state.shell ||
          state.shell.classList.contains("is-map-fullscreen");
        setFullscreenButtonState(fullscreenButton, active);
      }

      function refreshMapSize() {
        if (!state.map) {
          return;
        }
        window.setTimeout(function () {
          state.map.invalidateSize();
          if (state.bounds && state.bounds.length) {
            fitAllPositioned(state.bounds);
          }
        }, 180);
      }

      function toggleFullscreen() {
        if (!state.shell) {
          return;
        }

        if (
          document.fullscreenElement === state.shell &&
          document.exitFullscreen
        ) {
          document.exitFullscreen().finally(function () {
            syncFullscreenButton();
            refreshMapSize();
          });
          return;
        }

        if (!document.fullscreenElement && state.shell.requestFullscreen) {
          state.shell
            .requestFullscreen()
            .then(function () {
              syncFullscreenButton();
              refreshMapSize();
            })
            .catch(function () {
              state.shell.classList.toggle("is-map-fullscreen");
              syncFullscreenButton();
              refreshMapSize();
            });
          return;
        }

        state.shell.classList.toggle("is-map-fullscreen");
        syncFullscreenButton();
        refreshMapSize();
      }

      function setStatus(message, tone) {
        if (!statusNode) {
          return;
        }
        statusNode.textContent = message;
        statusNode.dataset.tone = tone || "neutral";
      }

      function positionItems(items) {
        return items.filter(function (item) {
          return (
            item &&
            item.position &&
            typeof item.position.latitude === "number" &&
            typeof item.position.longitude === "number"
          );
        });
      }

      function updateCount() {
        if (!countNode) {
          return;
        }
        countNode.textContent = String(positionItems(state.items).length);
      }

      function focusSupplier(id) {
        const marker = state.markers.get(Number(id));
        if (!marker || !state.map) {
          return;
        }
        state.map.setView(
          marker.getLatLng(),
          Math.max(state.map.getZoom(), 13),
          { animate: true },
        );
        marker.openPopup();
      }

      function bindRowActions() {
        document
          .querySelectorAll("[data-supplier-focus]")
          .forEach(function (button) {
            if (button.dataset.mapBound === "1") {
              return;
            }
            button.dataset.mapBound = "1";
            button.addEventListener("click", function () {
              focusSupplier(button.getAttribute("data-supplier-focus"));
            });
          });

        document
          .querySelectorAll("[data-supplier-live-update]")
          .forEach(function (button) {
            if (button.dataset.gpsBound === "1") {
              return;
            }
            button.dataset.gpsBound = "1";
            button.addEventListener("click", function () {
              if (!state.settings.allow_manual_override) {
                setStatus(
                  "La mise à jour navigateur est désactivée par l'administration.",
                  "warning",
                );
                return;
              }
              if (!navigator.geolocation) {
                setStatus(
                  "Le navigateur ne fournit pas la géolocalisation.",
                  "warning",
                );
                return;
              }

              const supplierId = Number(
                button.getAttribute("data-supplier-live-update") || "0",
              );
              const item = state.items.find(function (entry) {
                return Number(entry.id) === supplierId;
              });
              if (!supplierId || !item) {
                return;
              }

              button.disabled = true;
              setStatus("Lecture de la position GPS en cours...", "info");
              navigator.geolocation.getCurrentPosition(
                function (position) {
                  const body = new URLSearchParams({
                    entity_type: "supplier",
                    entity_id: String(supplierId),
                    latitude: String(position.coords.latitude || ""),
                    longitude: String(position.coords.longitude || ""),
                    accuracy_meters: String(position.coords.accuracy || ""),
                    location_label: item.name || "Fournisseur",
                    address_snapshot: item.address || "",
                    source: "browser",
                  });

                  fetch(config.updateUrl, {
                    method: "POST",
                    headers: {
                      "Content-Type":
                        "application/x-www-form-urlencoded; charset=UTF-8",
                    },
                    body: body.toString(),
                  })
                    .then(function (response) {
                      return response.json();
                    })
                    .then(function (json) {
                      if (!json || !json.ok) {
                        throw new Error(
                          (json && json.error) || "Mise à jour impossible",
                        );
                      }
                      setStatus(
                        "Position GPS mise à jour pour " +
                          (item.name || "le fournisseur") +
                          ".",
                        "success",
                      );
                      refresh();
                    })
                    .catch(function (error) {
                      setStatus(
                        error.message || "Erreur lors de la mise à jour GPS.",
                        "danger",
                      );
                    })
                    .finally(function () {
                      button.disabled = false;
                    });
                },
                function (error) {
                  button.disabled = false;
                  setStatus(
                    error.message || "Impossible de lire la position GPS.",
                    "danger",
                  );
                },
                {
                  enableHighAccuracy: true,
                  maximumAge: 5000,
                  timeout: 12000,
                },
              );
            });
          });
      }

      function renderMarkers() {
        if (!state.map) {
          return;
        }

        state.markers.forEach(function (marker) {
          marker.remove();
        });
        state.markers.clear();

        const positioned = positionItems(state.items);
        updateCount();

        if (!positioned.length) {
          setStatus("Aucune coordonnée exploitable pour l'instant.", "warning");
          state.map.setView(
            [
              Number(state.settings.map_center_lat || 5.348),
              Number(state.settings.map_center_lng || -4.03),
            ],
            Number(state.settings.map_zoom || 6),
          );
          return;
        }

        const bounds = [];
        positioned.forEach(function (item) {
          const marker = window.L.marker(
            [Number(item.position.latitude), Number(item.position.longitude)],
            { icon: markerIcon(item) },
          ).addTo(state.map);
          marker.bindPopup(popupHtml(item));
          if (item.name) {
            marker.bindTooltip(escapeHtml(item.name), {
              permanent: true,
              direction: "top",
              offset: [0, -12],
              className: "supplier-map-name-tooltip",
            });
          }
          state.markers.set(Number(item.id), marker);
          bounds.push([
            Number(item.position.latitude),
            Number(item.position.longitude),
          ]);
        });

        state.bounds = bounds;
        fitAllPositioned(bounds);

        setStatus(
          positioned.length + " fournisseur(s) positionné(s) sur la carte.",
          "success",
        );
      }

      function refresh() {
        return fetch(config.apiUrl, {
          headers: { "X-Requested-With": "XMLHttpRequest" },
        })
          .then(function (response) {
            return response.json();
          })
          .then(function (json) {
            if (!json || !json.ok) {
              throw new Error(
                (json && json.error) || "Lecture des positions impossible",
              );
            }
            state.settings = json.settings || state.settings;
            state.items = Array.isArray(json.items) ? json.items : [];
            renderMarkers();
            bindRowActions();
          })
          .catch(function (error) {
            setStatus(
              error.message || "Lecture des positions impossible.",
              "danger",
            );
          });
      }

      if (!state.settings.api_enabled) {
        updateCount();
        setStatus(
          "Le module GPS est désactivé par l'administration.",
          "warning",
        );
        container.innerHTML =
          '<div style="display:flex;align-items:center;justify-content:center;height:100%;min-height:320px;color:#64748b;font-weight:600">API GPS désactivée</div>';
        bindRowActions();
        return;
      }

      ensureLeafletCss();
      ensureMapFullscreenStyles();
      ensureLeafletScript()
        .then(function () {
          state.map = window.L.map(container, {
            zoomControl: true,
            attributionControl: true,
          }).setView(
            [
              Number(state.settings.map_center_lat || 5.348),
              Number(state.settings.map_center_lng || -4.03),
            ],
            Number(state.settings.map_zoom || 6),
          );

          window.L.tileLayer(
            state.settings.tile_url ||
              "https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png",
            {
              attribution:
                state.settings.tile_attribution ||
                "&copy; OpenStreetMap contributors",
              maxZoom: 19,
            },
          ).addTo(state.map);

          renderMarkers();
          bindRowActions();
          syncFullscreenButton();
          refreshMapSize();

          if (state.settings.realtime_enabled && config.apiUrl) {
            window.setInterval(
              refresh,
              Math.max(
                5000,
                Number(state.settings.poll_interval_seconds || 30) * 1000,
              ),
            );
          }
        })
        .catch(function () {
          setStatus("Chargement Leaflet impossible.", "danger");
        });

      if (fullscreenButton) {
        fullscreenButton.addEventListener("click", toggleFullscreen);
        document.addEventListener("fullscreenchange", function () {
          syncFullscreenButton();
          refreshMapSize();
        });
      }
    };

    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", run, { once: true });
      return;
    }

    run();
  };
})();

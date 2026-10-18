# Native Website label printing

## Supported software path

Use Website → Orders → Printer setup, or the order's Print or download label
action. Profiles belong to the active user, workspace, and workstation. Select
Mac or Windows, the installed printer/model, USB or network hint, paper layout,
and orientation. The browser does not discover or select the operating-system
printer; staff select the matching installed driver in the print dialog.

The implemented adapter is `browser_pdf`. DYMO, Rollo, Zebra, Brother and office
printer family choices describe driver profiles, not a claim that every model
supports shipping media. Direct/silent printing and ZPL/EPL2 are future adapters.

| Target | Connection / OS | Layout | Software evidence | Physical evidence |
| --- | --- | --- | --- | --- |
| DYMO LabelWriter 4XL | USB / Mac | Thermal 4x6 | PDF geometry, pixels, authenticated browser flow | Pending exact OS/driver and paper test |
| DYMO LabelWriter 4XL | USB / Windows | Thermal 4x6 | Same standard PDF path; Windows profile tested | Pending exact OS/driver and paper test |
| Other compatible 4-inch thermal model | USB or network / installed Mac or Windows driver | Thermal 4x6 | Generic driver/profile path | Exact model and driver must be verified |
| Compatible desktop laser/inkjet | Installed Mac or Windows driver | Letter or A4 | Same 4x6 image on the chosen sheet; browser profile tested | Pending actual-size and clipping test |

Use the model's official driver and supported stock. Do not send a 4x6 shipping
barcode to a narrower printer by shrinking it. Driver/model/OS availability must
be verified against the manufacturer, beginning with the user's USB DYMO 4XL.
See [DYMO support](https://www.dymo.com/user-guide.html).

## Calibration before postage

Download the no-postage test PDF for the chosen layout. In the OS/PDF print
dialog select matching paper, Actual Size / 100%, and the chosen orientation.
Disable fit-to-page, added headers/footers, and scaling. The square must measure
one inch, the two-inch ruler must measure two inches, and every border must be
visible. A Letter/A4 sheet retains the full 4x6 image with room for ordinary
printer margins. Follow the model's stock loading and alignment procedure.

Record the exact printer model, stock, connection, OS version, driver/version,
browser/PDF reader, orientation, measured output, and clipping result. A setup
page has no postage and does not prove that a real carrier barcode scans.

## Purchased labels and reprints

The carrier request asks for original PNG 4x6. A verified purchased shipment's
encrypted evidence supplies the original image. A separate unauthenticated,
host-restricted download stores it privately; credentials never accompany the
file fetch. Validation checks format, dimensions, resolution, decoding, byte
limits, and checksums before any document is ready.

Documents embed the complete lossless raster without resampling or redrawing
barcodes. Thermal paper is exactly 288×432 PDF points; Letter is 612×792;
A4 is 595.275591×841.889764. Sheet layouts keep the image at 288×432 points.
The PDF asks readers for no print scaling, but the chosen OS driver settings
still need calibration. EasyPost's own PDF can contain a 4x6 label on Letter;
the app uses the original PNG rather than guessing how to crop that PDF.
See [EasyPost label options](https://docs.easypost.com/docs/shipments/options).

Print creates a durable request with the saved profile and document checksum.
Only the operator's explicit confirmation records paper output. Browser
`afterprint`, opening/closing a preview, printer offline, and cancelling a dialog
do not record success or carrier handoff. Staff may record Failed or Cancelled,
then create a new print request using the retained postage.

Download/reprint reads stored files and never invokes postage purchase. Missing
or corrupt files require document preparation again, using existing postage.
Refunded/retired labels cannot be printed. Replacement postage has its own
reviewed rate and cost approval. Label documents always require active scoped
shipping-manager access; customer order links never expose them.

## Physical launch evidence

Begin with DYMO 4XL USB on both Mac and Windows. Verify actual dimensions,
address/barcode clipping, readable dark output, the real carrier barcode scan,
cancelled print, offline/failed output, and a repeat print with no second
postage charge. Verify another exact thermal model and an office Letter/A4
printer before advertising those hardware combinations as tested.

Software tests and rendered calibration PDFs are retained in release evidence.
No physical printer, live barcode or actual carrier delivery was tested during
implementation. Those remain Phase 5 launch checks.

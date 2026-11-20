# Teams show texts

Open a show in Teams → Events and choose **Text customers**. Add the message and photo, review the suggested audience, then send to the displayed number of customers. **Send me a preview** sends to the operator’s opted-in customer profile matched by their verified email. The preview receipt links back to the same review. **View results** shows delivery, replies and attributed purchases.

## Audience coverage

- Past editions and nearby shows come from accepted Teams sales history mappings. Use the existing reviewed Square location and original selling windows. Use confirmed, explicitly customer-linked Everbranch Pay receipts when an occurrence is linked to Pay.
- The saved area is shared by editions in the same reviewed series. Choose nearby series, cities with a state, and ZIP codes under **Edit nearby area**. Online orders must have an exact local shipping destination and confirmed Shopify payment and customer evidence. Missing source data shows as a review item.
- SMS eligibility is evaluated from current canonical profiles and recorded consent, then rechecked by the worker. Twilio opt-out refusals update the contact suppression. A show text with a photo uses the approved MMS attachment.

## Pilot and release

- Use the protected GitHub and Forge release path. Keep `TEAM_SHOW_TEXTS_ENABLED` off until the pilot is ready. Set `TEAM_SHOW_TEXTS_TENANTS` to the approved tenant’s numeric ID before enabling it for that pilot. An empty list makes the feature available to all eligible Teams tenants after activation.
- Confirm the tenant’s existing SMS access, sender, public HTTPS media storage and default queue worker. Verify operator access to the event source and confirm the reviewed 2020–2024 Charleston-area purchase mappings, saved area and opt-in imports before comparing against the earlier Charleston audience. Subscriber totals change as customers opt out and source coverage improves.
- Exercise desktop drop and mobile picker with an opted-in test phone. Confirm the photo is inline and the ticket link opens the intended destination. Reply and check that the results link to the inbox. Use the consented preview to test the carrier path before approving a customer send.
- The database queue writes jobs with campaign approvals when it shares the application database. A delivery started with an unknown provider outcome is left for review. A repeated confirmation of the same review opens the original results.

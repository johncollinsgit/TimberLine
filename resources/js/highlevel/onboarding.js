// Setup follows confirmed server state. Browsing a step never completes it.
export function setupStep(data) {
    if (!data.subscription.setup_allowed) return 'access';
    if (data.connection.status !== 'connected') return 'connection';
    if (!data.vehicles.length) return 'vehicles';
    if (!data.settings.policy_approved || !data.settings.tracking_enabled) return 'settings';
    if (!data.subscription.collection_active) return 'activation';
    return 'ready';
}

export function setupCopy(step, limit) {
    return {
        access: ['Fleet access needs attention', 'Ask your agency administrator to review this app’s installation and access. Your existing selections are preserved.', 'Review connection'],
        connection: ['Connect Bouncie to get started', 'Sign in to the Bouncie account that owns this client’s company vehicles. You will return here to choose which vehicles to include.', 'Connect account'],
        vehicles: ['Next: choose your vehicles', `Bouncie is connected. Select up to ${limit} company vehicles, then save your selection. Connecting the account alone does not select vehicles or start tracking.`, 'Select vehicles'],
        settings: ['Next: review your tracking settings', 'Your vehicles are saved. Add the approved company vehicle policy, choose how long locations are kept, and confirm whether to enable collection.', 'Review settings'],
        activation: ['Setup saved · activation pending', 'Your Bouncie account, vehicles and policy settings are saved. Everbranch support must activate this pilot before locations can be collected. You do not need to reconnect Bouncie.', 'Contact support for activation'],
        ready: ['Fleet setup complete', 'Your settings are saved and this account is eligible for collection. Locations appear when Bouncie reports a valid reading; a parked vehicle may have an older timestamp.', 'View fleet'],
    }[step];
}

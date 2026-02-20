import { TLAssetStore } from 'tldraw'

export function createAssetStore(serverUrl: string, token: string): TLAssetStore {
  // Ensure serverUrl doesn't have a trailing slash
  const baseUrl = serverUrl.replace(/\/$/, '')

  return {
    async upload(asset, file) {
      const formData = new FormData()
      formData.append('file', file)
      formData.append('assetId', asset.id)

      const res = await fetch(`${baseUrl}/uploads`, {
        method: 'POST',
        headers: {
            // Secure Uploads: Send token in Header
            'Authorization': `Bearer ${token}`
        },
        body: formData,
      })

      if (!res.ok) {
        throw new Error(`Upload failed: ${res.statusText}`)
      }

      const data = await res.json()
      // Return relative path (e.g. /uploads/user/file.png)
      return { src: data.url }
    },
    
    resolve(asset) {
      const src = asset.props.src
      if (!src) return null
      
      // If it's a relative path to our uploads, prepend the collab server URL
      if (src.startsWith('/uploads/')) {
          return `${baseUrl}${src}`
      }
      
      return src
    }
  }
}

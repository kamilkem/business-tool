import type { ReactNode } from "react"

import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from "@business-tool/ui/components/card"

export interface AuthScreenProps {
  title: ReactNode
  description?: ReactNode
  /** Links under the form, such as "Back to sign in". */
  footer?: ReactNode
  children: ReactNode
}

/**
 * The page around the signed-out forms (sign in, set a password, forgot
 * password): one centered card, full width on a phone, clear of the notch and
 * the home indicator when the timeclock runs as an installed PWA.
 */
export function AuthScreen({ title, description, footer, children }: AuthScreenProps) {
  return (
    <div className="flex min-h-svh items-center justify-center bg-muted px-4 pt-[max(1rem,env(safe-area-inset-top))] pb-[max(1rem,env(safe-area-inset-bottom))]">
      <Card className="w-full max-w-sm">
        <CardHeader>
          <CardTitle className="text-xl">{title}</CardTitle>
          {description && <CardDescription>{description}</CardDescription>}
        </CardHeader>
        <CardContent>{children}</CardContent>
        {footer && <CardFooter className="justify-center text-sm text-muted-foreground">{footer}</CardFooter>}
      </Card>
    </div>
  )
}
